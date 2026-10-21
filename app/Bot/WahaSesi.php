<?php

namespace App\Bot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * WAHA's sessions API: GET /api/sessions/{sesi}, GET /api/{sesi}/auth/qr and
 * POST /api/sessions/{sesi}/restart.
 */
class WahaSesi implements SesiWaha
{
    public function status(string $sesi): string
    {
        return (string) $this->jawab(fn (PendingRequest $http, string $url) => $http->acceptJson()->get("{$url}/api/sessions/{$sesi}"))->json('status');
    }

    public function qr(string $sesi): string
    {
        return $this->jawab(fn (PendingRequest $http, string $url) => $http->accept('image/png')->get("{$url}/api/{$sesi}/auth/qr", ['format' => 'image']))->body();
    }

    public function restart(string $sesi): void
    {
        $this->jawab(fn (PendingRequest $http, string $url) => $http->acceptJson()->post("{$url}/api/sessions/{$sesi}/restart"));
    }

    /**
     * @param  callable(PendingRequest, string): Response  $minta
     */
    private function jawab(callable $minta): Response
    {
        $url = rtrim((string) config('services.waha.url'), '/');

        if ($url === '') {
            throw new SesiGagal('WAHA_URL belum diisi');
        }

        try {
            $jawaban = $minta(Http::timeout((int) config('services.waha.timeout', 15))->withHeaders(['X-Api-Key' => (string) config('services.waha.key')]), $url);
        } catch (ConnectionException $e) {
            throw new SesiGagal('WAHA tidak bisa dihubungi: '.$e->getMessage(), previous: $e);
        }

        if (! $jawaban->successful()) {
            throw new SesiGagal("WAHA menolak ({$jawaban->status()}): ".mb_substr($jawaban->body(), 0, 200));
        }

        return $jawaban;
    }
}
