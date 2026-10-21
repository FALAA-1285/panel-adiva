<?php

namespace App\Bot;

/**
 * perintah KONFIG_BERUBAH through the signed webhook.
 */
class N8nKabarKonfig implements KabarKonfig
{
    public function __construct(private N8nWebhook $n8n) {}

    public function berubah(string $apa, array $isi = []): void
    {
        $this->n8n->kirim('KONFIG_BERUBAH', ['apa' => $apa, ...$isi]);
    }
}
