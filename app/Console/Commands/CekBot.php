<?php

namespace App\Console\Commands;

use App\Bot\TandaTangan;
use App\Support\Demo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks the connection to n8n (a signed PING to the command webhook) and WAHA (its
 * sessions), after N8N_* and WAHA_* are filled in .env.
 */
#[Signature('bot:cek')]
#[Description('Cek sambungan panel ke n8n dan WAHA')]
class CekBot extends Command
{
    public function handle(): int
    {
        $driver = config('services.bot.driver') === 'langsung' && ! Demo::aktif() ? 'langsung' : 'tiruan';
        $this->line("BOT_DRIVER: {$driver}".($driver === 'tiruan' ? ' (tidak ada yang dikirim; isi BOT_DRIVER=langsung dan APP_DEMO=false)' : ''));

        $n8n = $this->cekN8n();
        $waha = $this->cekWaha();

        return $n8n && $waha ? self::SUCCESS : self::FAILURE;
    }

    private function cekN8n(): bool
    {
        $url = (string) config('services.n8n.webhook_url');
        $rahasia = (string) config('services.n8n.webhook_secret');

        if ($url === '' || $rahasia === '') {
            $this->error('n8n: N8N_WEBHOOK_URL dan N8N_WEBHOOK_SECRET belum diisi');

            return false;
        }

        $body = (string) json_encode(['perintah' => 'PING', 'sumber' => 'panel', 'waktu' => now()->toIso8601String()]);

        try {
            $jawaban = Http::timeout((int) config('services.n8n.timeout', 15))
                ->withHeaders(TandaTangan::header($body, $rahasia))
                ->withBody($body, 'application/json')
                ->acceptJson()
                ->post($url);
        } catch (ConnectionException $e) {
            $this->error("n8n: tidak bisa dihubungi ({$e->getMessage()})");

            return false;
        }

        if ($jawaban->successful() && $jawaban->json('ok') === true) {
            $this->info('n8n: tersambung, tanda tangan diterima');

            return true;
        }

        $this->error("n8n: menjawab {$jawaban->status()} ".mb_substr($jawaban->body(), 0, 200));

        return false;
    }

    private function cekWaha(): bool
    {
        $url = rtrim((string) config('services.waha.url'), '/');

        if ($url === '') {
            $this->error('WAHA: WAHA_URL belum diisi');

            return false;
        }

        try {
            $jawaban = Http::timeout((int) config('services.waha.timeout', 15))
                ->withHeaders(['X-Api-Key' => (string) config('services.waha.key')])
                ->acceptJson()
                ->get("{$url}/api/sessions");
        } catch (Throwable $e) {
            $this->error("WAHA: tidak bisa dihubungi ({$e->getMessage()})");

            return false;
        }

        if (! $jawaban->successful()) {
            $this->error("WAHA: menjawab {$jawaban->status()} (cek WAHA_API_KEY)");

            return false;
        }

        foreach ((array) $jawaban->json() as $sesi) {
            $this->info('WAHA: sesi '.($sesi['name'] ?? '?').' · '.($sesi['status'] ?? '?'));
        }

        return true;
    }
}
