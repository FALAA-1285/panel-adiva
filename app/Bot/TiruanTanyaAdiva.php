<?php

namespace App\Bot;

/**
 * Stands in for n8n (demo mode, the tests): says plainly that nobody really answered.
 */
class TiruanTanyaAdiva implements TanyaAdiva
{
    public function tanya(string $bagian, string $pertanyaan, array $konteks = []): HasilUji
    {
        return new HasilUji('(Mode tiruan) Adiva belum tersambung ke n8n, pertanyaan ini tidak dijawab sungguhan.');
    }
}
