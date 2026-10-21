<?php

namespace App\Support;

use Illuminate\Support\Str;

class NomorWa
{
    /**
     * Digits only, starting with 62: "857 1234 0036", "0857-1234-0036" and
     * "6285712340036" all become "6285712340036".
     */
    public static function normalisasi(string $nomor): string
    {
        $angka = preg_replace('/\D/', '', $nomor) ?? '';

        if (Str::startsWith($angka, '62')) {
            return $angka;
        }

        return '62'.ltrim($angka, '0');
    }

    /**
     * WhatsApp's chat id for a number: "0857-1234-0036" → "6285712340036@c.us".
     */
    public static function chatId(string $nomor): string
    {
        return self::normalisasi($nomor).'@c.us';
    }

    /**
     * "6285712340036" → "+62 857-••••-••36" (Daftar.dc.html, LupaSandi.dc.html).
     */
    public static function samarkan(string $nomor): string
    {
        $lokal = Str::after(self::normalisasi($nomor), '62');

        return '+62 '.substr($lokal, 0, 3).'-••••-••'.substr($lokal, -2);
    }

    /**
     * "6287800415120" → "+62 878-••••-5120" (Pipeline.dc.html: a customer without a name).
     */
    public static function samarkanKartu(string $nomor): string
    {
        $lokal = Str::after(self::normalisasi($nomor), '62');

        return '+62 '.substr($lokal, 0, 3).'-••••-'.substr($lokal, -4);
    }

    /**
     * "6281234560007" → "0812-••••-••07" (Tim.dc.html: staff numbers).
     */
    public static function samarkanLokal(string $nomor): string
    {
        $lokal = self::lokal($nomor);

        return substr($lokal, 0, 4).'-••••-••'.substr($lokal, -2);
    }

    /**
     * "6281234560007" → "0812-••••-••••" (Settings.dc.html: staff list).
     */
    public static function samarkanPenuh(string $nomor): string
    {
        return substr(self::lokal($nomor), 0, 4).'-••••-••••';
    }

    /**
     * "6289612000077" → "0896-1200-••77" (Karyawan.dc.html: employee list).
     */
    public static function samarkanUjung(string $nomor): string
    {
        $lokal = self::lokal($nomor);

        return substr($lokal, 0, 4).'-'.substr($lokal, 4, 4).'-••'.substr($lokal, -2);
    }

    /**
     * "6281298760646" → "0812-••••-0646" (Karyawan.dc.html, Tim.dc.html, Settings.dc.html:
     * the PJ karyawan number).
     */
    public static function samarkanTengah(string $nomor): string
    {
        $lokal = self::lokal($nomor);

        return substr($lokal, 0, 4).'-••••-'.substr($lokal, -4);
    }

    /**
     * "6289612000077" → "089612000077".
     */
    private static function lokal(string $nomor): string
    {
        return '0'.Str::after(self::normalisasi($nomor), '62');
    }
}
