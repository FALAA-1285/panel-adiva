<?php

namespace App\Support;

use Illuminate\Support\Str;

class Nama
{
    /**
     * Avatar letter: "Falah" → "F", "Bu Sarah" → "S" (Tim.dc.html skips the honorific).
     */
    public static function inisial(string $nama): string
    {
        $tanpaSapaan = preg_replace('/^(bu|ibu|pak|bapak|mbak|mas)\s+/i', '', trim($nama)) ?? $nama;

        return Str::upper(Str::substr($tanpaSapaan, 0, 1));
    }

    /**
     * What a message calls the customer: the first name without the honorific
     * ("Rina Maharani" → "Rina", "Pak Hendra" → "Hendra"; BroadcastNew.dc.html preview).
     */
    public static function panggilan(string $nama): string
    {
        $tanpaSapaan = preg_replace('/^(bu|ibu|pak|bapak|mbak|mas)\s+/i', '', trim($nama)) ?? $nama;

        return Str::before($tanpaSapaan, ' ');
    }

    /**
     * Customer avatar: the first letters of the first two words, honorific included
     * ("Toko Berkah Jaya" → "TB", "Bu Wati" → "BW"; Customer.dc.html, OrderNew.dc.html).
     */
    public static function inisialCustomer(string $nama): string
    {
        $kata = preg_split('/\s+/', trim($nama), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // one word: its first letter and the first consonant after it ("Yoga" → "YG")
        if (count($kata) === 1) {
            preg_match('/^(\p{L})\P{L}*(?:[aiueo]*)(\p{L})?/iu', $kata[0], $huruf);

            return Str::upper(($huruf[1] ?? Str::substr($kata[0], 0, 1)).($huruf[2] ?? ''));
        }

        return Str::upper(implode('', array_map(fn (string $k) => Str::substr($k, 0, 1), array_slice($kata, 0, 2))));
    }
}
