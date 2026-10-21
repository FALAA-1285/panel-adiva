<?php

namespace App\Support;

use Carbon\CarbonInterface;

class Waktu
{
    /**
     * "5 jam lalu" (Tim.dc.html). Carbon's own Indonesian says "5 jam yang lalu".
     */
    public static function lalu(CarbonInterface $waktu): string
    {
        $menit = max(1, (int) $waktu->diffInMinutes(now()));

        return match (true) {
            $menit < 60 => "{$menit} menit lalu",
            $menit < 60 * 24 => intdiv($menit, 60).' jam lalu',
            default => intdiv($menit, 60 * 24).' hari lalu',
        };
    }

    /**
     * "30 Agu", "2 Sep" (Main.dc.html). Carbon's Indonesian writes August as "Agt".
     */
    public static function tanggalSingkat(CarbonInterface $waktu): string
    {
        $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        return $waktu->day.' '.$bulan[$waktu->month - 1];
    }

    /**
     * "13:41" (or "Hari ini 13:41"), "Kemarin 20:00", "27 Sep 01:00" (Otomasi.dc.html).
     * "Kemarin" only within the last 24 hours: the design writes yesterday 20:00 as
     * "Kemarin 20:00" but yesterday 01:00 as "27 Sep 01:00".
     */
    public static function relatif(CarbonInterface $waktu, bool $sebutHariIni = false): string
    {
        return match (true) {
            $waktu->isToday() => ($sebutHariIni ? 'Hari ini ' : '').$waktu->format('H:i'),
            $waktu->isYesterday() && $waktu->diffInHours(now()) < 24 => 'Kemarin '.$waktu->format('H:i'),
            default => $waktu->translatedFormat('j M H:i'),
        };
    }
}
