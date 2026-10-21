<?php

namespace App\Bot;

use App\Support\PengaturanAi;

/**
 * Asks n8n (perintah UJI_ADIVA): it runs the same agent with its tools, without sending
 * WhatsApp or writing the CRM, using the draft prompt when one is given.
 */
class N8nTanyaAdiva implements TanyaAdiva
{
    public function __construct(private N8nWebhook $n8n) {}

    public function tanya(string $bagian, string $pertanyaan, array $konteks = []): HasilUji
    {
        $jawaban = $this->n8n->kirim('UJI_ADIVA', [
            'bagian' => $bagian,
            'pertanyaan' => $pertanyaan,
            'kebijakan_id' => $konteks['kebijakan_id'] ?? null,
            'prompt' => $konteks['prompt'] ?? null,
            'pengaturan_ai' => PengaturanAi::nilai(),
        ], (int) config('services.n8n.timeout_uji', 60));

        if (blank($jawaban['jawaban'] ?? null)) {
            throw PerintahGagal::tidakMenjawab();
        }

        return new HasilUji(
            jawaban: (string) $jawaban['jawaban'],
            tool: array_values(array_map('strval', (array) ($jawaban['tool'] ?? []))),
            penilaian: is_array($jawaban['penilaian'] ?? null) ? $jawaban['penilaian'] : null,
            hasil: is_array($jawaban['hasil'] ?? null) ? $jawaban['hasil'] : null,
            produkId: is_numeric($jawaban['produk_id'] ?? null) ? (int) $jawaban['produk_id'] : null,
        );
    }
}
