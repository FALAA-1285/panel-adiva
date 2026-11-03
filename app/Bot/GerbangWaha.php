<?php

namespace App\Bot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WAHA through n8n: the panel on Vercel cannot reach WAHA on the VPS, so the workflow
 * "Bang Bana — Gerbang WAHA (panel adiva)" does it with the bot's own WAHA credential. Each
 * request is signed like the panel's other webhooks (TandaTangan, WAHA_GERBANG_SECRET).
 */
class GerbangWaha
{
    public static function siap(): bool
    {
        return filled(config('services.waha.gerbang_url')) && filled(config('services.waha.gerbang_secret'));
    }

    /**
     * @return string the WhatsApp message id
     *
     * @throws PengirimanGagal
     */
    public function kirimTeks(string $sesi, string $chatId, string $teks): string
    {
        return (string) ($this->minta('KIRIM_TEKS', ['session' => $sesi, 'chatId' => $chatId, 'teks' => $teks])['id'] ?? '');
    }

    /**
     * The real WhatsApp messages of these chats, oldest first per chat.
     *
     * @param  list<array{chatId: string, session?: string|null, sejak?: int, batas?: int}>  $chats
     * @return array<string, list<array<string, mixed>>> chat id => messages
     *
     * @throws PengirimanGagal
     */
    public function bacaPesan(array $chats): array
    {
        $hasil = [];

        foreach ((array) ($this->minta('BACA_PESAN', ['chats' => $chats], 60)['pesan'] ?? []) as $chat => $daftar) {
            /** @var list<array<string, mixed>> $baris */
            $baris = array_values(array_filter((array) $daftar, 'is_array'));
            usort($baris, fn (array $a, array $b) => (int) ($a['timestamp'] ?? 0) <=> (int) ($b['timestamp'] ?? 0));
            $hasil[(string) $chat] = $baris;
        }

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $isi
     * @return array<string, mixed>
     *
     * @throws PengirimanGagal
     */
    private function minta(string $perintah, array $isi, int $timeout = 25): array
    {
        if (! self::siap()) {
            throw new PengirimanGagal('Gerbang WAHA belum diatur (WAHA_GERBANG_URL, WAHA_GERBANG_SECRET)');
        }

        $body = (string) json_encode(['perintah' => $perintah, ...$isi, 'waktu' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $jawaban = Http::timeout($timeout)
                ->withHeaders(TandaTangan::header($body, (string) config('services.waha.gerbang_secret')))
                ->withBody($body, 'application/json')
                ->acceptJson()
                ->post((string) config('services.waha.gerbang_url'));
        } catch (ConnectionException $e) {
            throw new PengirimanGagal('Gerbang WAHA tidak bisa dihubungi: '.$e->getMessage(), previous: $e);
        }

        $hasil = $jawaban->json();

        if (! $jawaban->successful() || ! is_array($hasil) || ($hasil['ok'] ?? null) !== true) {
            throw new PengirimanGagal("Gerbang WAHA menolak ({$jawaban->status()}): ".(is_array($hasil) ? (string) ($hasil['alasan'] ?? '') : mb_substr($jawaban->body(), 0, 200)));
        }

        return $hasil;
    }
}
