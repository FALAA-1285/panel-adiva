<?php

namespace App\Enums;

/**
 * broadcast.status (design/source/Broadcast.dc.html).
 */
enum StatusBroadcast: string
{
    case Draf = 'DRAF';
    case Terjadwal = 'TERJADWAL';
    case Mengirim = 'MENGIRIM';
    case Dijeda = 'DIJEDA';
    case Selesai = 'SELESAI';

    public function label(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::Terjadwal => 'Terjadwal',
            self::Mengirim => 'Mengirim',
            self::Dijeda => 'Dijeda',
            self::Selesai => 'Selesai',
        };
    }

    /**
     * Tone of the Status component.
     */
    public function nada(): string
    {
        return match ($this) {
            self::Draf => 'gray',
            self::Terjadwal => 'violet',
            self::Mengirim => 'blue',
            self::Dijeda => 'amber',
            self::Selesai => 'green',
        };
    }
}
