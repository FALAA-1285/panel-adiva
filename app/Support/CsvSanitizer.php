<?php

namespace App\Support;

/**
 * Mitigates CSV Formula Injection (CWE-1236) by prepending a single quote
 * to any cell value starting with formula trigger characters (=, +, -, @, \t, \r).
 */
class CsvSanitizer
{
    /**
     * Sanitize a single cell value.
     */
    public static function sanitasi(mixed $nilai): mixed
    {
        if (! is_string($nilai)) {
            return $nilai;
        }

        // Numeric values without formula syntax do not need escaping
        if (is_numeric($nilai) && ! str_starts_with($nilai, '+')) {
            return $nilai;
        }

        if (preg_match('/^[=+\-@\t\r]/', $nilai)) {
            return "'".$nilai;
        }

        return $nilai;
    }

    /**
     * Sanitize an entire CSV row.
     *
     * @param  array<int, mixed>  $baris
     * @return array<int, mixed>
     */
    public static function baris(array $baris): array
    {
        return array_map(self::sanitasi(...), $baris);
    }
}
