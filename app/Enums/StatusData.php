<?php

namespace App\Enums;

/**
 * Whether a catalogue row is in use: produk/paket_reseller/kebijakan.status_data and
 * karyawan.status, as the n8n data tables write them. The columns stay plain strings.
 */
enum StatusData: string
{
    case Aktif = 'AKTIF';
    case Nonaktif = 'NONAKTIF';
}
