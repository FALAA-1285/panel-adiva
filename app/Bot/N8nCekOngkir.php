<?php

namespace App\Bot;

use App\Support\PengaturanToko;

/**
 * Asks n8n (perintah CEK_ONGKIR) to run its Cek_Ongkir_Mengantar tool, which holds the
 * Mengantar API key, pickup address and excluded couriers from CONFIG_TOKO.
 */
class N8nCekOngkir implements CekOngkir
{
    public function __construct(private N8nWebhook $n8n) {}

    public function cek(string $tujuan, float $beratKg): array
    {
        $jawaban = $this->n8n->kirim('CEK_ONGKIR', [
            'asal' => PengaturanToko::nilai('MENGANTAR_KOTA_ASAL'),
            'tujuan' => $tujuan,
            'berat_kg' => $beratKg,
        ], (int) config('services.n8n.timeout_uji', 60));

        return array_values(collect((array) ($jawaban['tarif'] ?? []))
            ->filter(fn ($t) => is_array($t) && isset($t['kurir'], $t['harga']))
            ->map(fn (array $t) => [
                'kurir' => (string) $t['kurir'],
                'layanan' => (string) ($t['layanan'] ?? ''),
                'harga' => (int) $t['harga'],
                'estimasi' => filled($t['estimasi'] ?? null) ? (string) $t['estimasi'] : null,
            ])
            ->all());
    }
}
