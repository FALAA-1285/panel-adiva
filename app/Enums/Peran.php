<?php

namespace App\Enums;

/**
 * Peran staf di panel (design/source/Daftar.dc.html).
 */
enum Peran: string
{
    case Supervisor = 'SUPERVISOR';
    case StafCs = 'STAF_CS';
    case PjKaryawan = 'PJ_KARYAWAN';

    public function label(): string
    {
        return match ($this) {
            self::Supervisor => 'Supervisor',
            self::StafCs => 'Staf CS',
            self::PjKaryawan => 'PJ karyawan',
        };
    }
}
