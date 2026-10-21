<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Working hours from CONFIG_TOKO: JAM_PESAN_OTOMATIS ("07:00-21:00") on every day
 * except HARI_LIBUR_OTOMATIS ("Minggu", or dates like "2026-12-25"). Deadlines such as
 * BATAS_BAYAR_JAM count these hours only (Otomasi.dc.html: "Malam dan hari libur
 * tidak ikut dihitung").
 */
final class JamKerja
{
    /**
     * @param  list<string>  $libur
     */
    private function __construct(
        public readonly string $mulai,
        public readonly string $selesai,
        public readonly array $libur,
    ) {}

    public static function dari(?string $jam, ?string $libur): self
    {
        [$mulai, $selesai] = array_pad(array_map('trim', explode('-', (string) $jam, 2)), 2, '');

        // no hours written yet: the 07:00-21:00 the workflows always assumed, not a crash at ACC
        return new self($mulai !== '' ? $mulai : '07:00', $selesai !== '' ? $selesai : '21:00', array_values(array_filter(array_map('trim', explode(',', (string) $libur)))));
    }

    public static function dariPengaturan(): self
    {
        $config = PengaturanToko::nilaiBanyak(['JAM_PESAN_OTOMATIS', 'HARI_LIBUR_OTOMATIS']);

        return self::dari($config['JAM_PESAN_OTOMATIS'], $config['HARI_LIBUR_OTOMATIS']);
    }

    public function hariLibur(CarbonInterface $hari): bool
    {
        return in_array($hari->translatedFormat('l'), $this->libur, true)
            || in_array($hari->toDateString(), $this->libur, true);
    }

    /**
     * "sekarang di jam kerja".
     */
    public function berlaku(CarbonInterface $waktu): bool
    {
        $jam = $waktu->format('H:i');

        return ! $this->hariLibur($waktu) && $jam >= $this->mulai && $jam < $this->selesai;
    }

    /**
     * Whole working hours left until $sampai ("sisa 2 jam kerja"); 0 once it has passed.
     */
    public function sisa(CarbonInterface $dari, CarbonInterface $sampai): int
    {
        $menit = 0;

        foreach ($this->hariKerja(CarbonImmutable::instance($dari)) as [$buka, $tutup]) {
            if ($buka >= $sampai) {
                break;
            }

            $menit += (int) $buka->diffInMinutes(min($tutup, CarbonImmutable::instance($sampai)));
        }

        return intdiv($menit, 60);
    }

    /**
     * The moment $jam working hours after $dari (the payment deadline after ACC).
     */
    public function tambah(CarbonInterface $dari, int $jam): CarbonImmutable
    {
        $menit = $jam * 60;
        $akhir = CarbonImmutable::instance($dari);

        foreach ($this->hariKerja($akhir) as [$buka, $tutup]) {
            $ada = (int) $buka->diffInMinutes($tutup);

            if ($ada >= $menit) {
                return $buka->addMinutes($menit);
            }

            $menit -= $ada;
            $akhir = $tutup;
        }

        return $akhir;
    }

    /**
     * Each working day's open stretch from $dari on, at most a year ahead.
     *
     * @return \Generator<int, array{CarbonImmutable, CarbonImmutable}>
     */
    private function hariKerja(CarbonImmutable $dari): \Generator
    {
        for ($hari = $dari->startOfDay(), $i = 0; $i < 366; $hari = $hari->addDay(), $i++) {
            if ($this->hariLibur($hari)) {
                continue;
            }

            $buka = max($dari, $hari->setTimeFromTimeString($this->mulai));
            $tutup = $hari->setTimeFromTimeString($this->selesai);

            if ($tutup > $buka) {
                yield [$buka, $tutup];
            }
        }
    }
}
