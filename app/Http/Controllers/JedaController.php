<?php

namespace App\Http\Controllers;

use App\Enums\KategoriAktivitas;
use App\Http\Requests\JedaRequest;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Support\Aktivitas;
use App\Support\NomorBot;
use App\Support\NomorWa;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ambil alih chat (Jeda.dc.html): the customers Adiva stays quiet for while an admin
 * answers, the same JEDA_BOT table the WhatsApp commands JEDA and LANJUT write to.
 */
class JedaController extends Controller
{
    /**
     * Shown in Riwayat jeda: pauses ended in the last days.
     */
    private const HARI_RIWAYAT = 3;

    public function index(): Response
    {
        $singkat = NomorBot::singkat();
        // in the order they were paused (Jeda.dc.html: Bu Wati, Toko Berkah Jaya, Mbak Lia)
        $aktif = JedaBot::query()->aktif()->orderBy('id')->get();

        return Inertia::render('jeda', [
            'ringkasan' => $this->ringkasan($aktif->count(), $aktif->whereNull('sampai')->count()),
            'aktif' => $aktif->map(fn (JedaBot $j) => [
                'id' => $j->id,
                'nama' => $j->nama,
                // kept apart so the column can lay them out instead of truncating
                // "0813-5566-••42 · CS 2" to "0813-5566-••42 · CS…"
                'nomor' => $j->nomor ? NomorWa::samarkanUjung($j->nomor) : null,
                'sesi' => $singkat[$j->sesi_wa ?? ''] ?? null,
                'sejak' => $this->hari($j->sejak),
                'sampai' => $j->sampai ? $j->sampai->format('H:i') : '∞',
                'sisa' => $this->sisa($j),
                'oleh' => $j->oleh,
                'alasan' => $j->alasan,
                'kabar' => $j->sudah_diberi_tahu ? 'Customer sudah dikabari' : 'Belum chat lagi',
            ]),
            'riwayat' => JedaBot::query()
                ->selesai()
                ->where('selesai_at', '>=', now()->subDays(self::HARI_RIWAYAT)->startOfDay())
                ->orderByDesc('selesai_at')
                ->get()
                ->map(fn (JedaBot $j) => [
                    'id' => $j->id,
                    'nama' => $j->nama,
                    'waktu' => $this->rentang($j->sejak, $j->selesai_at ?? now()),
                    'durasi' => number_format($this->menit($j), 0, ',', '.').' mnt',
                    'alasan' => $j->alasan,
                    'catatan' => $j->catatan_selesai,
                    'oleh' => $j->diakhiri_oleh,
                ]),
            'terakhir' => $this->chatTerakhir(),
        ]);
    }

    /**
     * "Jeda bot sekarang".
     */
    public function store(JedaRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $customer = $request->customer() ?? abort(404);
        $detik = $request->detik();
        $alasan = $request->validated('alasan');

        $jeda = JedaBot::create([
            'crm_customer_id' => $customer->id,
            'nomor' => $customer->nomor_wa,
            'chat_id' => $customer->chat_id,
            'nama' => $customer->nama,
            'sesi_wa' => $customer->sesi_wa,
            'sejak' => now(),
            'sampai' => $detik === null ? null : now()->addSeconds($detik),
            'oleh' => $user->name,
            'alasan' => $alasan,
        ]);

        // "Bu Wati sampai 14:30 · komplain kemasan bocor"
        Aktivitas::catat($user, KategoriAktivitas::Jeda, 'JEDA customer', collect([
            $customer->nama.' '.($jeda->sampai ? 'sampai '.$jeda->sampai->format('H:i') : 'tanpa batas'),
            $alasan,
        ])->filter()->implode(' · '), $jeda);

        Inertia::flash('toast', 'Adiva dijeda untuk '.$customer->namaTampil().($jeda->sampai ? ' sampai '.$jeda->sampai->format('H:i') : '').'.');

        return back();
    }

    /**
     * "Lanjutkan bot", with the hand-over note from "Lanjutkan & serah terima".
     */
    public function lanjut(Request $request, JedaBot $jeda): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        abort_if($jeda->selesai_at !== null, 404);
        $request->validate(['catatan' => ['nullable', 'string', 'max:500']]);
        $catatan = trim((string) $request->input('catatan'));

        $jeda->update([
            'selesai_at' => now(),
            'diakhiri_oleh' => $user->name,
            'catatan_selesai' => $catatan !== '' ? $catatan : 'Bot diaktifkan lagi dari panel',
        ]);

        // "Catatan disimpan di profil customer dan dibaca Adiva saat melanjutkan percakapan"
        // added to the admin's standing note (Customer.dc.html: "Catatan tetap · Ditulis
        // admin · dibaca Adiva"); `catatan` is the system's note
        if ($catatan !== '' && $jeda->customer) {
            $jeda->customer->update([
                'catatan_tetap' => collect([$jeda->customer->catatan_tetap, $catatan])->filter()->implode("\n"),
            ]);
        }

        Aktivitas::catat($user, KategoriAktivitas::Jeda, 'LANJUT customer', collect([$jeda->nama, $catatan])->filter()->implode(' · '), $jeda);

        Inertia::flash('toast', 'Adiva aktif lagi untuk '.($jeda->customer?->namaTampil() ?? $jeda->nama ?? $jeda->chat_id).'.');

        return back();
    }

    /**
     * The four numbers at the top.
     *
     * @return list<array{label: string, nilai: string, meta: string}>
     */
    private function ringkasan(int $aktif, int $tanpaBatas): array
    {
        $angka = JedaBot::query()
            ->selesai()
            ->toBase()
            ->selectRaw("count(*) filter (where selesai_at >= ?) as sesi, avg(extract(epoch from selesai_at - sejak) / 60) filter (where selesai_at >= ?) as rata, count(*) filter (where selesai_at >= ? and diakhiri_oleh = 'Sistem') as otomatis", [
                now()->subDays(30), now()->subDays(30), now()->subDays(7),
            ])
            ->first();

        return [
            ['label' => 'Sedang dijeda', 'nilai' => (string) $aktif, 'meta' => "{$tanpaBatas} tanpa batas waktu"],
            ['label' => 'Rata-rata durasi jeda', 'nilai' => round((float) ($angka->rata ?? 0)).' mnt', 'meta' => '30 hari terakhir · '.(int) ($angka->sesi ?? 0).' sesi'],
            ['label' => 'Lanjut otomatis', 'nilai' => (string) (int) ($angka->otomatis ?? 0), 'meta' => 'minggu ini · supervisor dikabari'],
            ['label' => 'Pengingat harian', 'nilai' => '09.00', 'meta' => 'Daftar jeda dikirim ke supervisor'],
        ];
    }

    /**
     * "± 50 mnt lagi", "tanpa batas · diingatkan 09.00", "kedaluwarsa · lanjut otomatis".
     */
    private function sisa(JedaBot $j): string
    {
        if ($j->sampai === null) {
            return 'tanpa batas · diingatkan 09.00';
        }

        if ($j->sampai->lessThanOrEqualTo(now())) {
            return 'kedaluwarsa · lanjut otomatis';
        }

        $menit = (int) now()->diffInMinutes($j->sampai);

        return $menit < 90
            ? '± '.max(10, (int) round($menit / 10) * 10).' mnt lagi'
            : '± '.round($menit / 60).' jam lagi';
    }

    /**
     * "Pakai chat terakhir: Nisa Aulia · 13:21": the latest chat that is not paused.
     *
     * @return array{nama: string, jam: string}|null
     */
    private function chatTerakhir(): ?array
    {
        $customer = CrmCustomer::query()
            ->whereNotNull('nama')
            ->whereNotNull('tgl_interaksi_terakhir')
            ->whereNotIn('chat_id', JedaBot::query()->aktif()->select('chat_id'))
            ->orderByDesc('tgl_interaksi_terakhir')
            ->first();

        return $customer && $customer->tgl_interaksi_terakhir
            ? ['nama' => (string) $customer->nama, 'jam' => $customer->tgl_interaksi_terakhir->format('H:i')]
            : null;
    }

    /**
     * "10:05" today, "Kemarin", "26 Sep" before that.
     */
    private function hari(CarbonInterface $waktu): string
    {
        return match (true) {
            $waktu->isToday() => $waktu->format('H:i'),
            $waktu->isYesterday() => 'Kemarin',
            default => $waktu->translatedFormat('j M'),
        };
    }

    /**
     * "27 Sep 14:02 → 15:10", "26 Sep 13:00 → 27 Sep 09:00", "Hari ini 10:05 → 13:42".
     */
    private function rentang(CarbonInterface $mulai, CarbonInterface $selesai): string
    {
        $awal = ($mulai->isToday() ? 'Hari ini' : $mulai->translatedFormat('j M')).' '.$mulai->format('H:i');
        $akhir = $selesai->isSameDay($mulai) ? $selesai->format('H:i') : $selesai->translatedFormat('j M H:i');

        return "{$awal} → {$akhir}";
    }

    private function menit(JedaBot $j): int
    {
        return (int) round($j->sejak->diffInMinutes($j->selesai_at ?? now()));
    }
}
