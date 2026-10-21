<?php

namespace App\Enums;

/**
 * log_order.status_order (design/source/Orders.dc.html).
 */
enum StatusOrder: string
{
    case MenungguKonfirmasi = 'MENUNGGU_KONFIRMASI';
    case Diacc = 'DIACC';
    case Lunas = 'LUNAS';
    case Dikirim = 'DIKIRIM';
    case Selesai = 'SELESAI';
    case Karyawan = 'KARYAWAN';
    case Ditolak = 'DITOLAK';
    case Dibatalkan = 'DIBATALKAN';
    case BatalOtomatis = 'BATAL OTOMATIS';
    // TUTUP from WhatsApp: closed without a message to the customer (e.g. moved to another CS)
    case Ditutup = 'DITUTUP';

    public function label(): string
    {
        return match ($this) {
            self::MenungguKonfirmasi => 'Menunggu ACC',
            self::Diacc => 'Menunggu bayar',
            self::Lunas => 'Lunas',
            self::Dikirim => 'Dikirim',
            self::Selesai => 'Selesai',
            self::Karyawan => 'Karyawan',
            self::Ditolak => 'Ditolak',
            self::Dibatalkan => 'Dibatalkan',
            self::BatalOtomatis => 'Batal otomatis',
            self::Ditutup => 'Ditutup',
        };
    }

    /**
     * Orders still being worked on.
     *
     * @return list<self>
     */
    public static function berjalan(): array
    {
        return [self::MenungguKonfirmasi, self::Diacc, self::Lunas, self::Dikirim, self::Karyawan];
    }

    /**
     * Rejected, cancelled, closed unpaid, or closed by hand ("Batal / tolak").
     *
     * @return list<self>
     */
    public static function batal(): array
    {
        return [self::Ditolak, self::Dibatalkan, self::BatalOtomatis, self::Ditutup];
    }

    /**
     * Tone of the Status component.
     */
    public function nada(): string
    {
        return match ($this) {
            self::MenungguKonfirmasi => 'amber',
            self::Diacc => 'blue',
            self::Lunas => 'green',
            self::Dikirim => 'violet',
            self::Selesai, self::Karyawan, self::Ditutup => 'gray',
            self::Ditolak, self::Dibatalkan, self::BatalOtomatis => 'red',
        };
    }
}
