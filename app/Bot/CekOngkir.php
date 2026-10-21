<?php

namespace App\Bot;

/**
 * Shipping rates from the warehouse (MENGANTAR_KOTA_ASAL) through Mengantar.
 */
interface CekOngkir
{
    /**
     * @return list<array{kurir: string, layanan: string, harga: int, estimasi: string|null}>
     *
     * @throws PerintahGagal
     */
    public function cek(string $tujuan, float $beratKg): array;
}
