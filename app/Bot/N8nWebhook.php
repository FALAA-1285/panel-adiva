<?php

namespace App\Bot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The panel's signed webhook to n8n (N8N_WEBHOOK_URL). One endpoint for everything the
 * panel asks of n8n; the "perintah" field tells n8n's Switch node which branch runs.
 */
class N8nWebhook
{
    /**
     * @param  array<string, mixed>  $isi
     * @return array<string, mixed> n8n's answer ({ok: true, …})
     *
     * @throws PerintahGagal n8n refused (with its reason) or did not answer
     */
    public function kirim(string $perintah, array $isi = [], ?int $timeout = null): array
    {
        $url = (string) config('services.n8n.webhook_url');
        $rahasia = (string) config('services.n8n.webhook_secret');

        if ($url === '' || $rahasia === '') {
            throw PerintahGagal::tidakMenjawab();
        }

        $body = (string) json_encode([
            'perintah' => $perintah,
            ...$isi,
            'sumber' => 'panel',
            // the same request sent twice (a retry after a timeout) runs once
            'request_id' => (string) ($isi['request_id'] ?? Str::uuid()),
            'waktu' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $jawaban = Http::timeout($timeout ?? (int) config('services.n8n.timeout', 15))
                ->withHeaders(TandaTangan::header($body, $rahasia))
                ->withBody($body, 'application/json')
                ->acceptJson()
                ->post($url);
        } catch (ConnectionException) {
            throw PerintahGagal::tidakMenjawab();
        }

        $hasil = $jawaban->json();

        if (is_array($hasil) && ($hasil['ok'] ?? null) === false) {
            throw new PerintahGagal((string) ($hasil['alasan'] ?? 'n8n menolak perintah ini.'));
        }

        if (! $jawaban->successful() || ! is_array($hasil) || ($hasil['ok'] ?? null) !== true) {
            throw PerintahGagal::tidakMenjawab();
        }

        return $hasil;
    }
}
