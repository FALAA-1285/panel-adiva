<?php

namespace App\Bot;

use Illuminate\Support\Facades\Log;

/**
 * Stands in for n8n (demo mode, the tests): the notice is only written to the log.
 */
class TiruanKabarKonfig implements KabarKonfig
{
    public function berubah(string $apa, array $isi = []): void
    {
        Log::info('Kabar n8n tiruan (tidak dikirim)', ['apa' => $apa, ...$isi]);
    }
}
