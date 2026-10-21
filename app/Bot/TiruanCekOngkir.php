<?php

namespace App\Bot;

/**
 * Stands in for Mengantar (demo mode, the tests): the rates Adiva quotes in
 * Inbox.dc.html ("Ongkir dari Sawojajar ke Dinoyo").
 */
class TiruanCekOngkir implements CekOngkir
{
    public function cek(string $tujuan, float $beratKg): array
    {
        return [
            ['kurir' => 'JNE', 'layanan' => 'REG', 'harga' => 38000, 'estimasi' => '2–3 hari'],
            ['kurir' => 'SiCepat', 'layanan' => 'REG', 'harga' => 35000, 'estimasi' => null],
            ['kurir' => 'GoSend', 'layanan' => 'Instant', 'harga' => 27000, 'estimasi' => null],
        ];
    }
}
