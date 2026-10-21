<?php

namespace App\Bot;

/**
 * Tells n8n that what it reads from the database changed: a newly published prompt or
 * new AI settings. n8n reads both itself, so it may ignore this (useful for a cache).
 */
interface KabarKonfig
{
    /**
     * @param  string  $apa  PROMPT or PENGATURAN_AI
     * @param  array<string, mixed>  $isi
     *
     * @throws PerintahGagal
     */
    public function berubah(string $apa, array $isi = []): void;
}
