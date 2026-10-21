<?php

namespace App\Support;

use App\Models\ConfigToko;

/**
 * The bot's WhatsApp numbers, from DAFTAR_NOMOR_BOT: "sesi=Label" or "sesi=Label=singkat"
 * entries separated by commas, e.g. "bangbana-waha=Nomor Utama=utama,bangbana-2=Nomor
 * Lawang=CS 2". The short name is what Jeda.dc.html writes under a customer.
 */
class NomorBot
{
    /**
     * @return array<string, string> WAHA session => label
     */
    public static function daftar(): array
    {
        return array_map(fn (array $nomor) => $nomor['label'], self::baca());
    }

    /**
     * @return array<string, string> WAHA session => short name (the label when there is none)
     */
    public static function singkat(): array
    {
        return array_map(fn (array $nomor) => $nomor['singkat'], self::baca());
    }

    /**
     * @return array<string, array{label: string, singkat: string}>
     */
    private static function baca(): array
    {
        $nomor = [];

        foreach (explode(',', (string) ConfigToko::query()->where('kunci', 'DAFTAR_NOMOR_BOT')->value('nilai')) as $isi) {
            [$sesi, $label, $singkat] = array_pad(array_map('trim', explode('=', $isi, 3)), 3, '');

            if ($sesi !== '') {
                $label = $label !== '' ? $label : $sesi;
                $nomor[$sesi] = ['label' => $label, 'singkat' => $singkat !== '' ? $singkat : $label];
            }
        }

        return $nomor;
    }
}
