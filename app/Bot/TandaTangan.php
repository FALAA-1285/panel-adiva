<?php

namespace App\Bot;

/**
 * Signs what the panel sends to n8n: HMAC-SHA256 over "timestamp.body" with
 * N8N_WEBHOOK_SECRET, so n8n can tell the request came from the panel and is fresh.
 */
class TandaTangan
{
    /**
     * The headers for a JSON body.
     *
     * @return array{X-Adiva-Timestamp: string, X-Adiva-Signature: string}
     */
    public static function header(string $body, string $rahasia, ?int $waktu = null): array
    {
        $waktu ??= now()->getTimestamp();

        return [
            'X-Adiva-Timestamp' => (string) $waktu,
            'X-Adiva-Signature' => 'sha256='.hash_hmac('sha256', $waktu.'.'.$body, $rahasia),
        ];
    }
}
