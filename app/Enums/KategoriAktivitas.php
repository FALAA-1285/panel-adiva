<?php

namespace App\Enums;

/**
 * activity_log.kategori. The column stays a plain string because n8n writes Adiva and
 * Sistem lines too; read it with tryFrom().
 */
enum KategoriAktivitas: string
{
    case Pesanan = 'PESANAN';
    case Jeda = 'JEDA';
    case Pengaturan = 'PENGATURAN';
    case Otomasi = 'OTOMASI';
    case Login = 'LOGIN';
    case Broadcast = 'BROADCAST';
    // only listed under "Semua aksi"
    case Percakapan = 'PERCAKAPAN';

    public function label(): string
    {
        return match ($this) {
            self::Pesanan => 'Pesanan',
            self::Jeda => 'Jeda',
            self::Pengaturan => 'Pengaturan',
            self::Otomasi => 'Otomasi',
            self::Login => 'Login',
            self::Broadcast => 'Broadcast',
            self::Percakapan => 'Percakapan',
        };
    }

    /**
     * The "Semua aksi" choices on Log aktivitas, in the design's order.
     *
     * @return list<self>
     */
    public static function pilihanFilter(): array
    {
        return [self::Pesanan, self::Jeda, self::Pengaturan, self::Otomasi, self::Login, self::Broadcast];
    }
}
