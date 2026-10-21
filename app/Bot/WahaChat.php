<?php

namespace App\Bot;

use App\Support\Demo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The small things the bot asks WAHA about a chat besides sending: the phone number behind an
 * "@lid", "read" ticks and the "typing…" indicator. None of them is worth failing a message
 * for, so a failure only goes to the log. Does nothing in demo mode or without WAHA_URL.
 */
class WahaChat
{
    /**
     * "6281234567890@c.us" behind a "…@lid" chat id, or null when WAHA does not know it.
     */
    public function nomorDariLid(string $sesi, string $lid): ?string
    {
        $jawaban = $this->panggil('GET', '/api/'.rawurlencode($sesi).'/lids/'.rawurlencode($lid), timeout: 5);
        $isi = is_array($jawaban) && array_is_list($jawaban) ? ($jawaban[0] ?? null) : $jawaban;
        $pn = is_array($isi) ? ($isi['pn'] ?? null) : null;

        return filled($pn) ? (string) $pn : null;
    }

    public function tandaiDibaca(string $sesi, string $chatId, string $idPesan): void
    {
        $this->panggil('POST', '/api/sendSeen', ['session' => $sesi, 'chatId' => $chatId, 'messageId' => $idPesan], 5);
    }

    public function mengetik(string $sesi, string $chatId): void
    {
        $this->panggil('POST', '/api/startTyping', ['session' => $sesi, 'chatId' => $chatId], 5);
    }

    public function berhentiMengetik(string $sesi, string $chatId): void
    {
        $this->panggil('POST', '/api/stopTyping', ['session' => $sesi, 'chatId' => $chatId], 5);
    }

    /**
     * @param  array<string, mixed>|null  $isi
     * @return array<mixed>|null
     */
    private function panggil(string $metode, string $jalur, ?array $isi = null, int $timeout = 5): ?array
    {
        $url = rtrim((string) config('services.waha.url'), '/');

        if ($url === '' || Demo::aktif()) {
            return null;
        }

        try {
            $permintaan = Http::timeout($timeout)->withHeaders(['X-Api-Key' => (string) config('services.waha.key')])->acceptJson();
            $jawaban = $isi === null ? $permintaan->get($url.$jalur) : $permintaan->post($url.$jalur, $isi);

            return $jawaban->successful() && is_array($jawaban->json()) ? $jawaban->json() : null;
        } catch (Throwable $e) {
            Log::warning("WAHA {$metode} {$jalur} gagal: ".$e->getMessage());

            return null;
        }
    }
}
