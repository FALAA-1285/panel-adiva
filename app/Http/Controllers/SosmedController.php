<?php

namespace App\Http\Controllers;

use App\Bot\PengirimanGagal;
use App\Bot\PengirimSosmed;
use App\Enums\KategoriAktivitas;
use App\Enums\PengirimPesan;
use App\Jobs\KirimPesanSosmed;
use App\Models\ConfigToko;
use App\Models\SosmedKontak;
use App\Models\SosmedPesan;
use App\Models\User;
use App\Support\Aktivitas;
use App\Support\HakAkses;
use App\Support\Nama;
use App\Support\PengaturanToko;
use App\Support\Perangkat;
use App\Support\Waktu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Instagram & Facebook: the DMs Chatbot Sosmed answers (docs/kontrak-n8n.md §10), replied
 * to and paused from here, and the page's connection to Meta.
 */
class SosmedController extends Controller
{
    /** @var array<string, string> tab => label */
    private const TAB = ['semua' => 'Semua', 'ig' => 'Instagram', 'fb' => 'Facebook', 'admin' => 'Butuh admin', 'jeda' => 'Dijeda'];

    /** What the page saves through PengaturanToko (logged with before / after). */
    public const ISIAN = ['META_PAGE_ID', 'META_IG_ID', 'META_APP_ID', 'META_VERIFY_TOKEN', 'SOSMED_JEDA_JAM', 'SOSMED_BOT_IG', 'SOSMED_BOT_FB'];

    public function index(Request $request): Response
    {
        $user = $request->user() ?? abort(403);
        $tab = array_key_exists((string) $request->query('tab'), self::TAB) ? (string) $request->query('tab') : 'semua';
        $cari = trim((string) $request->query('cari'));
        $semua = SosmedKontak::query()->get();
        $kontak = $request->filled('pilih') ? SosmedKontak::query()->find($request->integer('pilih')) : null;

        $daftar = $semua
            ->filter(fn (SosmedKontak $k) => $this->diTab($k, $tab))
            ->filter(fn (SosmedKontak $k) => $cari === '' || Str::contains(Str::lower($k->namaTampil().' '.$k->username.' '.$k->pesan_terakhir), Str::lower($cari)))
            ->sortByDesc(fn (SosmedKontak $k) => $k->terakhir()?->getTimestamp() ?? 0)
            ->take(200)
            ->values();

        return Inertia::render('sosmed', [
            'tab' => collect(self::TAB)->map(fn (string $label, string $kode) => [
                'kode' => $kode,
                'label' => $label,
                'n' => $semua->filter(fn (SosmedKontak $k) => $this->diTab($k, $kode))->count(),
            ])->values(),
            'aktifTab' => $tab,
            'cari' => $cari,
            'daftar' => $daftar->map(fn (SosmedKontak $k) => $this->baris($k)),
            'kontak' => $kontak ? $this->utas($kontak) : null,
            'sambungan' => $this->sambungan($user),
            'bolehBalas' => HakAkses::boleh($user, 'balas'),
        ]);
    }

    /**
     * An admin's reply: sent through Meta (queued), and the bot keeps quiet for
     * SOSMED_JEDA_JAM, as when an admin replies from the Instagram / Facebook app.
     */
    public function balas(Request $request, SosmedKontak $kontak): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $teks = trim((string) $request->validate(['teks' => ['required', 'string', 'max:1000']], ['teks.required' => 'Tulis balasannya dulu.'])['teks']);

        $pesan = SosmedPesan::create([
            'sosmed_kontak_id' => $kontak->id,
            'waktu' => now(),
            'pengirim' => PengirimPesan::Admin,
            'user_id' => $user->id,
            'teks' => $teks,
            'status_kirim' => 'MENUNGGU',
        ]);
        $this->jeda($kontak, $user, $this->jamJeda());
        KirimPesanSosmed::dispatch($pesan);

        Aktivitas::catat($user, KategoriAktivitas::Percakapan, 'Balas DM '.$kontak->platformLabel(), $kontak->namaTampil().' · '.Str::limit($teks, 60));
        Inertia::flash('toast', 'Balasan dikirim ke '.$kontak->namaTampil().'.');

        return back();
    }

    /**
     * "Jeda bot": Adiva stops answering this person for SOSMED_JEDA_JAM.
     */
    public function jedakan(Request $request, SosmedKontak $kontak): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $jam = $this->jamJeda();

        $this->jeda($kontak, $user, $jam);
        Aktivitas::catat($user, KategoriAktivitas::Jeda, 'JEDA '.$kontak->platformLabel(), $kontak->namaTampil()." · {$jam} jam");
        Inertia::flash('toast', 'Adiva dijeda untuk '.$kontak->namaTampil().' sampai '.$kontak->jedaSampai()?->format('H:i').'.');

        return back();
    }

    public function lanjut(Request $request, SosmedKontak $kontak): RedirectResponse
    {
        $user = $request->user() ?? abort(403);

        $kontak->update(['status_bot' => 'AKTIF', 'jeda_sampai' => null, 'jeda_oleh' => null]);
        Aktivitas::catat($user, KategoriAktivitas::Jeda, 'LANJUT '.$kontak->platformLabel(), $kontak->namaTampil());
        Inertia::flash('toast', 'Adiva aktif lagi untuk '.$kontak->namaTampil().'.');

        return back();
    }

    /**
     * Sambungan: the page's ids, the bot switches, and the secrets (write-only: never sent
     * back, logged only as "diganti").
     */
    public function simpanSambungan(Request $request): RedirectResponse
    {
        $oleh = $request->user() ?? abort(403);
        $perangkat = Perangkat::dari($request->userAgent());
        $data = $request->validate([
            'META_PAGE_ID' => ['nullable', 'string', 'max:64', 'regex:/^\d*$/'],
            'META_IG_ID' => ['nullable', 'string', 'max:64', 'regex:/^\d*$/'],
            'META_APP_ID' => ['nullable', 'string', 'max:64', 'regex:/^\d*$/'],
            'META_VERIFY_TOKEN' => ['nullable', 'string', 'max:100'],
            'SOSMED_JEDA_JAM' => ['required', 'integer', 'min:1', 'max:168'],
            'SOSMED_BOT_IG' => ['required', Rule::in(['AKTIF', 'MATI'])],
            'SOSMED_BOT_FB' => ['required', Rule::in(['AKTIF', 'MATI'])],
            'META_PAGE_TOKEN_BARU' => ['nullable', 'string', 'max:1000'],
            'META_APP_SECRET_BARU' => ['nullable', 'string', 'max:200'],
        ], [
            'META_PAGE_ID.regex' => 'Page ID berupa angka.',
            'META_IG_ID.regex' => 'ID akun Instagram berupa angka.',
            'META_APP_ID.regex' => 'App ID berupa angka.',
            'SOSMED_JEDA_JAM.*' => 'Isi 1–168 jam.',
        ]);

        PengaturanToko::ubahBanyak(collect(self::ISIAN)->mapWithKeys(fn (string $kunci) => [$kunci => trim((string) ($data[$kunci] ?? ''))])->all(), $oleh, $perangkat);

        foreach (['META_PAGE_TOKEN' => 'Page access token Meta', 'META_APP_SECRET' => 'App secret Meta'] as $kunci => $nama) {
            if (filled($baru = $data[$kunci.'_BARU'] ?? null)) {
                $config = ConfigToko::query()->updateOrCreate(['kunci' => $kunci], ['nilai' => trim((string) $baru)]);
                Aktivitas::catat($oleh, KategoriAktivitas::Pengaturan, 'Ubah pengaturan', "{$nama} diganti", $config, ['perangkat' => $perangkat]);
            }
        }

        Inertia::flash('toast', 'Sambungan Instagram & Facebook disimpan.');

        return back();
    }

    /**
     * "Uji sambungan": asks Meta which page the token belongs to.
     */
    public function ujiSambungan(PengirimSosmed $meta): RedirectResponse
    {
        try {
            $halaman = $meta->cekSambungan();
        } catch (PengirimanGagal $e) {
            throw ValidationException::withMessages(['sambungan' => $e->getMessage()]);
        }

        Inertia::flash('toast', 'Tersambung ke '.$halaman.'.');

        return back();
    }

    private function diTab(SosmedKontak $k, string $tab): bool
    {
        return match ($tab) {
            'ig' => $k->platform !== 'FB',
            'fb' => $k->platform === 'FB',
            'admin' => $k->butuhAdmin(),
            'jeda' => $k->dijeda(),
            default => true,
        };
    }

    private function jamJeda(): int
    {
        return max(1, (int) PengaturanToko::nilai('SOSMED_JEDA_JAM'));
    }

    private function jeda(SosmedKontak $kontak, User $oleh, int $jam): void
    {
        // n8n compares jeda_sampai as an ISO time (Tentukan Langkah)
        $kontak->update(['status_bot' => 'JEDA', 'jeda_sampai' => now()->addHours($jam)->toIso8601String(), 'jeda_oleh' => $oleh->name]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(SosmedKontak $k): array
    {
        $terakhir = $k->terakhir();

        return [
            'id' => $k->id,
            'nama' => $k->namaTampil(),
            'inisial' => Nama::inisialCustomer($k->nama ?: ($k->username ?: '?')),
            'platform' => $k->platform === 'FB' ? 'FB' : 'IG',
            'teks' => $k->pesan_terakhir ?? '',
            'jam' => $terakhir ? ($terakhir->isToday() ? $terakhir->format('H:i') : Waktu::tanggalSingkat($terakhir)) : '',
            'status' => $this->status($k),
        ];
    }

    /**
     * @return array{label: string, nada: string}|null
     */
    private function status(SosmedKontak $k): ?array
    {
        return match (true) {
            $k->dijeda() => ['label' => 'Dijeda s/d '.$k->jedaSampai()?->format('H:i'), 'nada' => 'gray'],
            $k->butuhAdmin() => ['label' => 'Butuh admin', 'nada' => 'amber'],
            $k->tag_terakhir === 'ORDER' => ['label' => 'Mau order', 'nada' => 'green'],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function utas(SosmedKontak $k): array
    {
        $pesan = $k->pesan()->with('user')->orderBy('waktu')->orderBy('id')->get();

        return [
            ...$this->baris($k),
            'username' => $k->username,
            'foto' => $k->foto_url,
            'profil' => $k->platform !== 'FB' && $k->username ? 'https://instagram.com/'.$k->username : null,
            'dijeda' => $k->dijeda(),
            'info' => [
                ['Platform', $k->platformLabel()],
                ['Bot', $k->dijeda() ? 'Dijeda'.($k->jeda_oleh ? ' oleh '.$k->jeda_oleh : '').' sampai '.$k->jedaSampai()?->format('H:i') : 'Aktif'],
                ['Pertama chat', $k->pertama() ? Waktu::tanggalSingkat($k->pertama()).' '.$k->pertama()->format('H:i') : '—'],
                ['Jumlah pesan', (string) $k->jumlah_pesan],
                ['Minat order', $k->minat_order === 'YA' ? 'Ya' : 'Belum'],
                ['Tanda terakhir', match ($k->tag_terakhir) {
                    'ORDER' => 'Diarahkan ke order',
                    'ADMIN' => 'Perlu admin',
                    default => '—',
                }],
            ],
            'pesan' => $pesan->map(fn (SosmedPesan $p) => [
                'id' => $p->id,
                'jenis' => match ($p->pengirim) {
                    PengirimPesan::Customer => 'customer',
                    PengirimPesan::Adiva => 'adiva',
                    PengirimPesan::Admin => 'admin',
                    PengirimPesan::Sistem => 'sistem',
                },
                'oleh' => match ($p->pengirim) {
                    PengirimPesan::Adiva => 'Adiva · AI',
                    PengirimPesan::Admin => 'Admin · '.($p->user->name ?? 'aplikasi '.$k->platformLabel()),
                    default => null,
                },
                'teks' => $p->teks,
                'jam' => $p->waktu->format('H:i'),
                'kirim' => in_array($p->status_kirim, ['MENUNGGU', 'GAGAL'], true) ? $p->status_kirim : null,
                'galat' => $p->status_kirim === 'GAGAL' ? ($p->meta['galat'] ?? null) : null,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sambungan(User $user): array
    {
        $nilai = PengaturanToko::nilaiBanyak([...self::ISIAN, 'META_PAGE_TOKEN', 'META_APP_SECRET']);
        $webhook = (string) config('services.n8n.webhook_url');

        return [
            'boleh' => HakAkses::boleh($user, 'pengaturan'),
            'tersambung' => filled($nilai['META_PAGE_TOKEN']),
            // the secrets themselves never leave the server
            'tokenTersimpan' => filled($nilai['META_PAGE_TOKEN']),
            'rahasiaTersimpan' => filled($nilai['META_APP_SECRET']),
            'nilai' => collect(self::ISIAN)->mapWithKeys(fn (string $k) => [$k => (string) $nilai[$k]])->all(),
            // Chatbot Sosmed's webhook sits next to the panel's (…/webhook/panel-perintah)
            'webhook' => $webhook !== '' ? preg_replace('#/[^/]+$#', '/meta-bangbana', $webhook) : null,
        ];
    }
}
