<?php

namespace App\Bot;

/**
 * "Uji tanya Adiva" / "Uji draf": a customer question answered by Adiva in test mode
 * (nothing goes to WhatsApp or the CRM).
 */
interface TanyaAdiva
{
    /**
     * @param  string  $bagian  KATALOG, KEBIJAKAN or PROMPT
     * @param  array{kebijakan_id?: int|null, prompt?: array{versi: int, teks: string}|null}  $konteks
     *
     * @throws PerintahGagal
     */
    public function tanya(string $bagian, string $pertanyaan, array $konteks = []): HasilUji;
}
