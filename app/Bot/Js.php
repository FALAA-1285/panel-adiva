<?php

namespace App\Bot;

/**
 * The few JavaScript rules the bot's text code was written against (it came from n8n's Code
 * nodes): what counts as "true", what Number() and String() make of a value. Keeping them
 * lets the PHP read the same as the JavaScript it replaced, line by line.
 */
final class Js
{
    /**
     * JavaScript truthiness: null, false, 0, NaN and "" are false; any array is true.
     */
    public static function benar(mixed $v): bool
    {
        return ! ($v === null || $v === false || $v === 0 || $v === '' || (is_float($v) && ($v == 0.0 || is_nan($v))));
    }

    /**
     * `a || b`
     */
    public static function atau(mixed $a, mixed $b): mixed
    {
        return self::benar($a) ? $a : $b;
    }

    /**
     * String(v)
     */
    public static function teks(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            is_int($v), is_string($v) => (string) $v,
            is_float($v) => self::angkaKeTeks($v),
            is_array($v) && array_is_list($v) => implode(',', array_map(fn ($x) => $x === null ? '' : self::teks($x), $v)),
            default => '[object Object]',
        };
    }

    /**
     * Number(v). A missing value (undefined) is NaN; give NAN for it yourself.
     */
    public static function angka(mixed $v): float
    {
        if ($v === null) {
            return 0.0;
        }

        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }

        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        if (is_array($v)) {
            return $v === [] ? 0.0 : (array_is_list($v) && count($v) === 1 ? self::angka($v[0]) : NAN);
        }

        $t = trim((string) $v, " \t\n\r\v\f");

        return match (true) {
            $t === '' => 0.0,
            is_numeric($t) && ! str_contains(strtolower($t), 'x') => (float) $t,
            (bool) preg_match('/^0x[0-9a-f]+$/i', $t) => (float) hexdec($t),
            in_array($t, ['Infinity', '+Infinity'], true) => INF,
            $t === '-Infinity' => -INF,
            default => NAN,
        };
    }

    /**
     * Whether Number(v) is a finite number (isFinite).
     */
    public static function hingga(float $n): bool
    {
        return ! is_nan($n) && ! is_infinite($n);
    }

    /**
     * String.prototype.trim: also the Unicode spaces PHP's trim() leaves.
     */
    public static function rapat(string $s): string
    {
        return (string) preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $s);
    }

    /**
     * An object (not a list): what `typeof x === 'object' && !Array.isArray(x)` meant.
     */
    public static function objek(mixed $v): bool
    {
        return is_array($v) && $v !== [] && ! array_is_list($v);
    }

    /**
     * Math.round: halves go up, also below zero (-2.5 → -2).
     */
    public static function bulat(float $n): int
    {
        return (int) floor($n + 0.5);
    }

    private static function angkaKeTeks(float $f): string
    {
        if (is_nan($f)) {
            return 'NaN';
        }

        if (is_infinite($f)) {
            return $f > 0 ? 'Infinity' : '-Infinity';
        }

        if (floor($f) === $f && abs($f) < 1e21) {
            return number_format($f, 0, '.', '');
        }

        return rtrim(rtrim(sprintf('%.15F', $f), '0'), '.');
    }
}
