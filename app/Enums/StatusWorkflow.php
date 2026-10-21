<?php

namespace App\Enums;

/**
 * workflow.status (design/source/Otomasi.dc.html): running on its schedule, or on
 * standby until another workflow fails (Notifikasi Error).
 */
enum StatusWorkflow: string
{
    case Aktif = 'AKTIF';
    case Siaga = 'SIAGA';
    case Nonaktif = 'NONAKTIF';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Siaga => 'Siaga',
            self::Nonaktif => 'Nonaktif',
        };
    }

    /**
     * Tone of the Status component.
     */
    public function nada(): string
    {
        return match ($this) {
            self::Aktif => 'green',
            self::Siaga => 'blue',
            self::Nonaktif => 'gray',
        };
    }

    /**
     * Workflows that count as running ("7 workflow aktif").
     *
     * @return list<string>
     */
    public static function berjalan(): array
    {
        return [self::Aktif->value, self::Siaga->value];
    }
}
