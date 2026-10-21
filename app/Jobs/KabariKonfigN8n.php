<?php

namespace App\Jobs;

use App\Bot\KabarKonfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "KONFIG_BERUBAH" to n8n after a prompt is published or the AI settings change. It is
 * only a notice (n8n reads the database itself), so the page does not wait for it.
 */
class KabariKonfigN8n implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  string  $apa  PROMPT or PENGATURAN_AI
     * @param  array<string, mixed>  $isi
     */
    public function __construct(public string $apa, public array $isi = []) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(KabarKonfig $kabar): void
    {
        $kabar->berubah($this->apa, $this->isi);
    }
}
