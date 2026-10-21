<?php

namespace App\Jobs;

use App\Bot\AlurPesanMasuk;
use App\Bot\PesanMasuk;
use App\Enums\JenisNotifikasi;
use App\Support\KabarBot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One WhatsApp message reported by WAHA, worked through (AlurPesanMasuk). It runs in the queue
 * so the webhook can answer WAHA at once.
 */
class ProsesPesanMasuk implements ShouldQueue
{
    use Queueable;

    /** A second try could tell the customer twice that a CS is helping. */
    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $body  WAHA's webhook JSON
     */
    public function __construct(public array $body) {}

    public function handle(AlurPesanMasuk $alur): void
    {
        $alur->proses(PesanMasuk::dariWebhook($this->body));
    }

    /**
     * The message was not worked through: nobody answered the customer, so the supervisor is told.
     */
    public function failed(?Throwable $galat): void
    {
        $pesan = PesanMasuk::dariWebhook($this->body);

        KabarBot::notifikasi(
            JenisNotifikasi::WorkflowError,
            'Pesan masuk gagal diproses',
            "{$pesan->dari()} · ".mb_substr($pesan->body(), 0, 120).' · '.mb_substr((string) $galat?->getMessage(), 0, 160),
        );
    }
}
