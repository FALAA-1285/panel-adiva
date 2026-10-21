<?php

namespace App\Bot;

/**
 * Shipping rates straight from Mengantar (BOT_DRIVER=lokal), with the API key, pickup
 * address and hidden couriers from CONFIG_TOKO.
 */
class LokalCekOngkir implements CekOngkir
{
    public function __construct(private PengirimanMengantar $mengantar) {}

    public function cek(string $tujuan, float $beratKg): array
    {
        return $this->mengantar->tarif($tujuan, $beratKg);
    }
}
