<?php

namespace App\Http\Controllers;

use App\Bot\CekOngkir;
use App\Bot\PerintahGagal;
use App\Enums\KategoriAktivitas;
use App\Enums\Peran;
use App\Http\Requests\SimpanPengaturanRequest;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\PromptVersi;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\Aktivitas;
use App\Support\KabarWa;
use App\Support\NomorBot;
use App\Support\NomorWa;
use App\Support\PengaturanAi;
use App\Support\PengaturanToko;
use App\Support\Perangkat;
use App\Support\Rupiah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan (Settings.dc.html): every CONFIG_TOKO value Adiva reads, plus the
 * chatbot's AI settings. Saved together with "Simpan perubahan".
 */
class PengaturanController extends Controller
{
    /** Where "Uji cek ongkir" checks to (Inbox.dc.html: "dari Sawojajar ke Dinoyo"). */
    private const UJI_ONGKIR_TUJUAN = 'DINOYO, LOWOKWARU, MALANG, JAWA TIMUR';

    public function index(): Response
    {
        $nilai = PengaturanToko::nilaiBanyak([...SimpanPengaturanRequest::ISIAN, 'INVOICE_TERAKHIR', 'MENGANTAR_API_KEY']);
        $koneksi = $this->koneksiTerakhir();
        $prompt = PromptVersi::query()->aktif()->orderByDesc('versi')->first();

        return Inertia::render('pengaturan', [
            'disimpan' => $this->disimpan(),
            'nilai' => collect(SimpanPengaturanRequest::ISIAN)->mapWithKeys(fn (string $kunci) => [
                $kunci => in_array($kunci, SimpanPengaturanRequest::DAFTAR, true)
                    ? array_values(array_filter(array_map('trim', explode(',', (string) $nilai[$kunci])), fn (string $v) => $v !== ''))
                    : (string) $nilai[$kunci],
            ]),
            'ai' => array_map(fn (?string $v) => (string) $v, PengaturanAi::nilai()),
            'modelPilihan' => PengaturanAi::MODEL,
            'invoiceTerakhir' => $nilai['INVOICE_TERAKHIR'],
            // the key itself never leaves the server
            'apiKeyTersimpan' => filled($nilai['MENGANTAR_API_KEY']),
            // Notifikasi WhatsApp: the rows switched off
            'kabarWaMati' => KabarWa::mati(),
            'staf' => User::query()->whereNull('nonaktif_at')->orderBy('id')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'nama' => $user->name,
                'nomor' => match (true) {
                    ! $user->nomor_wa => null,
                    $user->peran === Peran::PjKaryawan => NomorWa::samarkanTengah($user->nomor_wa),
                    default => NomorWa::samarkanPenuh($user->nomor_wa),
                },
                'peran' => $user->peran->label(),
                // Settings.dc.html: what the number may do on WhatsApp
                'diWhatsapp' => $user->peran === Peran::PjKaryawan ? 'Order karyawan' : 'Semua perintah',
                'semua' => $user->peran !== Peran::PjKaryawan,
            ]),
            'nomorBot' => collect(NomorBot::daftar())->map(fn (string $label, string $sesi) => [
                'sesi' => $sesi,
                'label' => $label,
                'status' => match ($koneksi[$sesi]->status ?? null) {
                    'TERHUBUNG' => ['label' => 'Terhubung', 'nada' => 'green'],
                    'PUTUS' => ['label' => 'Putus', 'nada' => 'red'],
                    default => null,
                },
            ])->values(),
            'terakhirDicek' => collect($koneksi)->max('waktu')?->format('H:i'),
            'promptBagian' => $prompt ? count($prompt->bagian ?? []) : null,
            'pj' => User::pjKaryawan()->name ?? Peran::PjKaryawan->label(),
        ]);
    }

    /**
     * "Simpan perubahan": only the values that changed are written, each one logged.
     */
    /**
     * "Uji cek ongkir": one rate check from the warehouse to a fixed place nearby, to see
     * that Mengantar answers with the saved key and pickup.
     */
    public function ujiOngkir(CekOngkir $ongkir): JsonResponse
    {
        try {
            $tarif = $ongkir->cek(self::UJI_ONGKIR_TUJUAN, 1);
        } catch (PerintahGagal $e) {
            throw ValidationException::withMessages(['ongkir' => $e->getMessage()]);
        }

        if ($tarif === []) {
            throw ValidationException::withMessages(['ongkir' => 'Mengantar tidak memberi tarif untuk tujuan uji.']);
        }

        return response()->json([
            'hasil' => 'Ke Dinoyo, Malang · 1 kg: '.collect($tarif)
                ->map(fn (array $t) => trim("{$t['kurir']} {$t['layanan']}").' '.Rupiah::teks($t['harga']).($t['estimasi'] ? " ({$t['estimasi']})" : ''))
                ->implode(' · '),
        ]);
    }

    /**
     * Notifikasi WhatsApp: one row switched on or off, saved at once.
     */
    public function kabarWa(Request $request): RedirectResponse
    {
        $data = $request->validate(['baris' => ['required', Rule::in(KabarWa::BARIS)], 'nyala' => ['required', 'boolean']]);
        $mati = array_values(array_diff(KabarWa::mati(), [$data['baris']]));

        KabarWa::simpan($data['nyala'] ? $mati : [...$mati, $data['baris']], $request->user() ?? abort(403), Perangkat::dari($request->userAgent()));
        Inertia::flash('toast', $data['nyala'] ? 'Kabar WhatsApp dinyalakan.' : 'Kabar WhatsApp dimatikan. Notifikasinya tetap masuk panel.');

        return back();
    }

    public function simpan(SimpanPengaturanRequest $request): RedirectResponse
    {
        $oleh = $request->user() ?? abort(403);
        $perangkat = Perangkat::dari($request->userAgent());

        PengaturanToko::ubahBanyak(
            collect(SimpanPengaturanRequest::ISIAN)->mapWithKeys(fn (string $kunci) => [
                $kunci => in_array($kunci, SimpanPengaturanRequest::DAFTAR, true)
                    ? implode(',', $request->validated($kunci, []))
                    : (string) $request->validated($kunci),
            ])->all(),
            $oleh,
            $perangkat,
        );

        PengaturanAi::ubahBanyak(
            array_map(fn (mixed $v) => (string) $v, $request->validated('ai')),
            $oleh,
            $perangkat,
        );

        // the key itself never reaches the log (no before/after, so no "Kembalikan" either)
        if (filled($kunci = $request->validated('MENGANTAR_API_KEY_BARU'))) {
            $config = ConfigToko::query()->updateOrCreate(['kunci' => 'MENGANTAR_API_KEY'], ['nilai' => trim((string) $kunci)]);
            Aktivitas::catat($oleh, KategoriAktivitas::Pengaturan, 'Ubah pengaturan', 'API key Mengantar diganti', $config, ['perangkat' => $perangkat]);
        }

        Inertia::flash('toast', 'Pengaturan disimpan.');

        return back();
    }

    /**
     * "Disimpan 28 Sep 12:10 · Dina": the latest change to a value on this page.
     */
    private function disimpan(): ?string
    {
        $terakhir = ActivityLog::query()
            ->where('kategori', KategoriAktivitas::Pengaturan->value)
            ->whereIn('data->kunci', [...SimpanPengaturanRequest::ISIAN, ...array_keys(PengaturanAi::KUNCI)])
            ->latest('waktu')
            ->first();

        return $terakhir ? 'Disimpan '.$terakhir->waktu->translatedFormat('j M H:i').' · '.$terakhir->pelaku : null;
    }

    /**
     * The latest connection check per WAHA session (Pantau Koneksi WhatsApp).
     *
     * @return array<string, WorkflowEvent>
     */
    private function koneksiTerakhir(): array
    {
        return WorkflowEvent::query()
            ->where('jenis', 'KONEKSI')
            ->latest('waktu')
            ->get()
            ->unique(fn (WorkflowEvent $event) => $event->data['sesi'] ?? null)
            ->keyBy(fn (WorkflowEvent $event) => (string) ($event->data['sesi'] ?? ''))
            ->all();
    }
}
