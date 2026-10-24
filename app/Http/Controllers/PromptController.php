<?php

namespace App\Http\Controllers;

use App\Enums\KategoriAktivitas;
use App\Enums\StatusPrompt;
use App\Enums\TahapFunnel;
use App\Jobs\KabariKonfigN8n;
use App\Models\PromptVersi;
use App\Models\UjiAdiva;
use App\Models\User;
use App\Support\Aktivitas;
use App\Support\DiffBaris;
use App\Support\PengaturanAi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Prompt Adiva (Prompt.dc.html): the sections of Adiva's system prompt, what the
 * draft changes against the active version, the last draft test and the versions.
 * n8n reads the AKTIF version's teks_lengkap, so publishing takes effect at once.
 */
class PromptController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user() ?? abort(403);

        if (! PromptVersi::query()->exists()) {
            PromptVersi::inisialisasiStandar($user);
        }

        $aktif = PromptVersi::query()->aktif()->with('pembuat:id,name')->latest('versi')->first();
        $draf = PromptVersi::query()->draf()->latest('versi')->first();
        $bagian = collect(($draf ?? $aktif)->bagian ?? [])
            ->map(fn (array $b) => [
                'no' => $b['no'],
                'judul' => $b['judul'],
                'diubah' => $draf !== null && $b['teks'] !== $aktif?->teksBagian($b['no']),
            ]);

        // ?bagian=N, else the first section the draft changes, else the first one
        $no = $request->has('bagian') && $bagian->contains('no', $request->integer('bagian'))
            ? $request->integer('bagian')
            : ($bagian->firstWhere('diubah')['no'] ?? $bagian->first()['no'] ?? null);

        return Inertia::render('prompt', [
            'aktif' => $aktif ? ['versi' => $aktif->versi, 'keterangan' => $this->keterangan($aktif, $user)] : null,
            'draf' => $draf ? ['versi' => $draf->versi, 'ringkasan' => $draf->ringkasan] : null,
            'bagian' => $bagian->values(),
            'terpilih' => $no === null ? null : $this->terpilih(($draf ?? $aktif) ?? abort(404), $draf ? $aktif : null, $no),
            'uji' => $draf ? $this->uji($draf) : null,
            'model' => $this->model(),
            'riwayat' => PromptVersi::query()
                ->with('pembuat:id,name')
                ->orderByDesc('versi')
                ->get()
                ->map(fn (PromptVersi $v) => [
                    'id' => $v->id,
                    'versi' => $v->versi,
                    'ringkasan' => $v->ringkasan,
                    'keterangan' => $this->keterangan($v, $user),
                    'status' => $v->status->value,
                    'label' => $v->status->label(),
                    'nada' => $v->status->nada(),
                ]),
        ]);
    }

    /**
     * "Terbitkan v15": the draft becomes the version Adiva reads.
     */
    public function terbitkan(Request $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);

        $draf = DB::transaction(function () use ($user) {
            $draf = PromptVersi::query()->draf()->lockForUpdate()->firstOrFail();

            PromptVersi::query()->aktif()->update(['status' => StatusPrompt::Arsip]);
            $draf->update(['status' => StatusPrompt::Aktif, 'diterbitkan_at' => now(), 'user_id' => $user->id]);

            return $draf;
        });

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Terbitkan prompt', "v{$draf->versi} · {$draf->ringkasan}", $draf);
        // n8n reads prompt_versi.teks_lengkap of the AKTIF version itself; this is only a notice
        KabariKonfigN8n::dispatch('PROMPT', ['versi' => $draf->versi]);

        Inertia::flash('toast', "Prompt v{$draf->versi} diterbitkan.");

        return back();
    }

    /**
     * "Ubah" on a section: its new text goes into the draft. Without a draft, one is
     * made from the active version first.
     */
    public function ubahBagian(Request $request, int $no): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $data = $request->validate([
            'teks' => ['required', 'string', 'max:20000'],
            'ringkasan' => ['nullable', 'string', 'max:120'],
        ], ['teks.required' => 'Teks bagian tidak boleh kosong.']);

        $draf = DB::transaction(function () use ($no, $data, $user) {
            $draf = PromptVersi::query()->draf()->lockForUpdate()->first();
            $dasar = $draf ?? PromptVersi::query()->aktif()->latest('versi')->first() ?? abort(404);
            $bagian = collect($dasar->bagian);
            $judul = $bagian->firstWhere('no', $no)['judul'] ?? abort(404);
            $baru = $bagian->map(fn (array $b) => $b['no'] === $no ? [...$b, 'teks' => $data['teks']] : $b)->values()->all();
            $ringkasan = filled($data['ringkasan'] ?? null) ? (string) $data['ringkasan'] : ($draf->ringkasan ?? "Ubah bagian {$no}. {$judul}");

            if ($draf) {
                $draf->update(['bagian' => $baru, 'ringkasan' => $ringkasan]);

                return $draf;
            }

            return PromptVersi::create([
                'versi' => (int) PromptVersi::query()->max('versi') + 1,
                'ringkasan' => $ringkasan,
                'bagian' => $baru,
                'status' => StatusPrompt::Draf,
                'user_id' => $user->id,
            ]);
        });

        $judul = collect($draf->bagian)->firstWhere('no', $no)['judul'] ?? '';
        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Ubah draf prompt', "v{$draf->versi} · {$no}. {$judul}", $draf);

        Inertia::flash('toast', "Draf v{$draf->versi} disimpan.");

        return to_route('prompt', ['bagian' => $no]);
    }

    /**
     * "Teks penuh": the whole prompt as Adiva reads it (the draft when there is one).
     */
    public function teks(): HttpResponse
    {
        $versi = PromptVersi::query()->draf()->latest('versi')->first()
            ?? PromptVersi::query()->aktif()->latest('versi')->first()
            ?? abort(404);

        return response((string) $versi->teks_lengkap, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * "Buang draf".
     */
    public function buangDraf(Request $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $draf = PromptVersi::query()->draf()->firstOrFail();
        $draf->delete();

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Buang draf prompt', "v{$draf->versi} · {$draf->ringkasan}");

        Inertia::flash('toast', "Draf v{$draf->versi} dibuang.");

        return back();
    }

    /**
     * "Pulihkan": an archived version's sections become the draft, to be tested and
     * published like any other change.
     */
    public function pulihkan(Request $request, PromptVersi $versi): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        abort_unless($versi->status === StatusPrompt::Arsip, 404);

        $draf = DB::transaction(function () use ($versi, $user) {
            $isi = ['ringkasan' => $versi->ringkasan, 'bagian' => $versi->bagian, 'user_id' => $user->id];
            $draf = PromptVersi::query()->draf()->lockForUpdate()->first();

            if ($draf) {
                $draf->update($isi);

                return $draf;
            }

            return PromptVersi::create([
                ...$isi,
                'versi' => (int) PromptVersi::query()->max('versi') + 1,
                'status' => StatusPrompt::Draf,
            ]);
        });

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Pulihkan prompt', "v{$versi->versi} → draf v{$draf->versi}", $draf);

        Inertia::flash('toast', "v{$versi->versi} dipulihkan sebagai draf v{$draf->versi}.");

        return back();
    }

    /**
     * The section open in the middle panel, its lines marked against the active version.
     *
     * @return array{no: int, judul: string, keterangan: string|null, teks: string, baris: list<array{jenis: string, no: int|null, teks: string}>, tambah: int|null, hapus: int|null}
     */
    private function terpilih(PromptVersi $versi, ?PromptVersi $banding, int $no): array
    {
        $bagian = collect($versi->bagian)->firstWhere('no', $no) ?? abort(404);
        // without a draft there is nothing to compare: every line is unchanged
        $baris = DiffBaris::banding($banding ? $banding->teksBagian($no) : $bagian['teks'], $bagian['teks']);
        $hitung = fn (string $jenis) => $banding ? count(array_filter($baris, fn (array $b) => $b['jenis'] === $jenis)) : null;

        return [
            'no' => $no,
            'judul' => $bagian['judul'],
            'keterangan' => $bagian['keterangan'] ?? null,
            // what "Ubah" starts from
            'teks' => $bagian['teks'],
            'baris' => $baris,
            'tambah' => $hitung('tambah'),
            'hapus' => $hitung('hapus'),
        ];
    }

    /**
     * The last test of the current draft.
     *
     * @return array{pertanyaan: string, jawaban: string|null, butuhAdmin: bool|null, tahap: string|null, alasan: string|null}|null
     */
    private function uji(PromptVersi $draf): ?array
    {
        $uji = UjiAdiva::query()->where('bagian', 'PROMPT')->where('prompt_versi_id', $draf->id)->latest('id')->first();

        if (! $uji) {
            return null;
        }

        $tahap = $uji->hasil['tahap'] ?? null;

        return [
            'pertanyaan' => $uji->pertanyaan,
            'jawaban' => $uji->jawaban,
            'butuhAdmin' => $uji->hasil['butuh_admin'] ?? null,
            'tahap' => $tahap === null ? null : (TahapFunnel::tryFrom($tahap)?->label() ?? $tahap),
            'alasan' => $uji->hasil['alasan'] ?? null,
        ];
    }

    /**
     * "gemini-3.6-flash · temperature 0,4", from the chatbot workflow's settings.
     */
    private function model(): string
    {
        $ai = PengaturanAi::nilai();

        return collect([
            $ai['AI_MODEL_UTAMA'],
            $ai['AI_TEMPERATURE'] === null ? null : 'temperature '.str_replace('.', ',', $ai['AI_TEMPERATURE']),
        ])->filter()->implode(' · ');
    }

    /**
     * "Anda · belum diterbitkan" for a draft, "29 Sep · Falah" for a published version.
     */
    private function keterangan(PromptVersi $versi, User $user): string
    {
        if ($versi->status === StatusPrompt::Draf) {
            $oleh = $versi->user_id === $user->id ? 'Anda' : $versi->pembuat?->name;

            return collect([$oleh, 'belum diterbitkan'])->filter()->implode(' · ');
        }

        return collect([$versi->diterbitkan_at?->translatedFormat('j M'), $versi->pembuat?->name])->filter()->implode(' · ');
    }
}
