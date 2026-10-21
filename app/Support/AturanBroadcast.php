<?php

namespace App\Support;

use App\Models\BroadcastPenerima;
use Carbon\CarbonInterface;

/**
 * "Aturan kirim aman" (Broadcast.dc.html): the limits every campaign is sent with
 * (App\Jobs\KirimBroadcast).
 */
class AturanBroadcast
{
    public const JEDA_MIN_DETIK = 15;

    public const JEDA_MAKS_DETIK = 45;

    public const BATAS_HARIAN = 300;

    public const GAGAL_BERUNTUN = 5;

    /** "Jam kirim 08.00–20.00 WIB". */
    public const JAM_MULAI = 8;

    public const JAM_SELESAI = 20;

    /**
     * "Kecepatan" on Buat kampanye: the wait between two messages, and the seconds per
     * message the estimate uses (BroadcastNew.dc.html: 30 and 14).
     *
     * @var array<string, array{label: string, detik: int, min: int, maks: int}>
     */
    public const KECEPATAN = [
        'AMAN' => ['label' => 'Aman · jeda 15–45 dtk', 'detik' => 30, 'min' => self::JEDA_MIN_DETIK, 'maks' => self::JEDA_MAKS_DETIK],
        'NORMAL' => ['label' => 'Normal · 8–20 dtk', 'detik' => 14, 'min' => 8, 'maks' => 20],
    ];

    /**
     * "Kota": the choices and the CRM kota values each one covers (null: every city).
     *
     * @var array<string, array{label: string, kota: list<string>|null}>
     */
    public const KOTA = [
        'MALANG_RAYA' => ['label' => 'Malang Raya (Malang, Batu, Kab. Malang)', 'kota' => ['Malang', 'Kota Malang', 'Batu', 'Kota Batu', 'Kab. Malang', 'Kabupaten Malang', 'Lawang', 'Kepanjen']],
        'SEMUA' => ['label' => 'Semua kota', 'kota' => null],
        'SURABAYA' => ['label' => 'Surabaya', 'kota' => ['Surabaya', 'Kota Surabaya']],
        'PASURUAN' => ['label' => 'Pasuruan', 'kota' => ['Pasuruan', 'Kota Pasuruan', 'Kab. Pasuruan', 'Kabupaten Pasuruan']],
    ];

    /**
     * "Interaksi terakhir": within N days, or over 90 days for a win-back. The keys are
     * words, so PHP keeps them strings.
     *
     * @var array<string, string>
     */
    public const INTERAKSI = [
        '90_HARI' => 'Interaksi terakhir ≤ 90 hari',
        '7_HARI' => '≤ 7 hari',
        '30_HARI' => '≤ 30 hari',
        'WINBACK' => '> 90 hari (win-back)',
    ];

    /**
     * The six rules, as written in the design.
     *
     * @return list<array{label: string, nilai: string}>
     */
    public static function daftar(): array
    {
        return [
            ['label' => 'Jeda antar pesan', 'nilai' => 'acak '.self::JEDA_MIN_DETIK.'–'.self::JEDA_MAKS_DETIK.' dtk'],
            ['label' => 'Batas harian', 'nilai' => self::BATAS_HARIAN.' pesan / hari'],
            ['label' => 'Jam kirim', 'nilai' => '08.00–20.00 WIB'],
            ['label' => 'Selalu dikecualikan', 'nilai' => 'opt-out · sedang dijeda'],
            ['label' => 'Rem otomatis', 'nilai' => 'jeda jika '.self::GAGAL_BERUNTUN.' gagal beruntun'],
            ['label' => 'Kata kunci berhenti', 'nilai' => 'STOP → ditandai opt-out'],
        ];
    }

    /**
     * A random wait before the next message, in seconds.
     */
    public static function jeda(string $kecepatan): int
    {
        $k = self::KECEPATAN[$kecepatan] ?? self::KECEPATAN['AMAN'];

        return random_int($k['min'], $k['maks']);
    }

    /**
     * Inside "Jam kirim"?
     */
    public static function bolehKirim(CarbonInterface $waktu): bool
    {
        return $waktu->hour >= self::JAM_MULAI && $waktu->hour < self::JAM_SELESAI;
    }

    /**
     * 08.00 of the next sending day: today when it is still early, otherwise tomorrow.
     */
    public static function jamBukaBerikutnya(CarbonInterface $waktu): CarbonInterface
    {
        $buka = $waktu->copy()->setTime(self::JAM_MULAI, 0);

        return $waktu->hour < self::JAM_MULAI ? $buka : $buka->addDay();
    }

    /**
     * Messages every campaign sent today, against "Batas harian".
     */
    public static function terkirimHariIni(): int
    {
        return BroadcastPenerima::query()->where('terkirim_at', '>=', now()->startOfDay())->count();
    }

    /**
     * Average wait between two messages, for the finish estimate.
     */
    public static function jedaRataRataDetik(): int
    {
        return intdiv(self::JEDA_MIN_DETIK + self::JEDA_MAKS_DETIK, 2);
    }
}
