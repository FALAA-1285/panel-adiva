<?php

namespace App\Support;

/**
 * An amount in Indonesian words, as the invoice prints it under the grand total:
 * 1.250.000 → "Satu Juta Dua Ratus Lima Puluh Ribu Rupiah".
 */
class Terbilang
{
    private const SATUAN = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];

    public static function rupiah(int $nilai): string
    {
        $kata = trim((string) preg_replace('/\s+/', ' ', self::angka(abs($nilai))));

        return ($kata !== '' ? $kata : 'Nol').' Rupiah';
    }

    public static function angka(int $n): string
    {
        $sisa = fn (int $x, int $per) => $x % $per !== 0 ? ' '.self::angka($x % $per) : '';

        return match (true) {
            $n < 12 => self::SATUAN[$n],
            $n < 20 => self::angka($n - 10).' Belas',
            $n < 100 => self::angka(intdiv($n, 10)).' Puluh'.$sisa($n, 10),
            $n < 200 => 'Seratus'.$sisa($n, 100),
            $n < 1000 => self::angka(intdiv($n, 100)).' Ratus'.$sisa($n, 100),
            $n < 2000 => 'Seribu'.$sisa($n, 1000),
            $n < 1_000_000 => self::angka(intdiv($n, 1000)).' Ribu'.$sisa($n, 1000),
            $n < 1_000_000_000 => self::angka(intdiv($n, 1_000_000)).' Juta'.$sisa($n, 1_000_000),
            default => self::angka(intdiv($n, 1_000_000_000)).' Milyar'.$sisa($n, 1_000_000_000),
        };
    }
}
