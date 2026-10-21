<?php

namespace App\Support;

class Rupiah
{
    /**
     * 1482000 → "Rp1.482.000", as written in the design.
     */
    public static function teks(int $nilai): string
    {
        return 'Rp'.number_format($nilai, 0, ',', '.');
    }
}
