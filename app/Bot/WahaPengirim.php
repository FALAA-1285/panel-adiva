<?php

namespace App\Bot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WAHA's HTTP API: POST /api/sendText (or /api/sendImage) with the session of the bot
 * number the customer last used (crm_customer.sesi_wa).
 */
class WahaPengirim implements PengirimWa
{
    public function kirimTeks(string $sesi, string $chatId, string $teks): string
    {
        return $this->kirim('sendText', ['session' => $sesi, 'chatId' => $chatId, 'text' => $teks]);
    }

    public function kirimGambar(string $sesi, string $chatId, string $url, string $mimetype, string $caption): string
    {
        return $this->kirim('sendImage', [
            'session' => $sesi,
            'chatId' => $chatId,
            'file' => ['mimetype' => $mimetype, 'url' => $url, 'filename' => basename((string) parse_url($url, PHP_URL_PATH))],
            'caption' => $caption,
        ]);
    }

    public function kirimBerkas(string $sesi, string $chatId, string $isi, string $mimetype, string $namaFile, string $caption): string
    {
        return $this->kirim('sendFile', [
            'session' => $sesi,
            'chatId' => $chatId,
            'file' => ['mimetype' => $mimetype, 'filename' => $namaFile, 'data' => base64_encode($isi)],
            'caption' => $caption,
        ]);
    }

    /**
     * @param  array<string, mixed>  $isi
     */
    private function kirim(string $jalur, array $isi): string
    {
        $url = rtrim((string) config('services.waha.url'), '/');

        if ($url === '') {
            throw new PengirimanGagal('WAHA_URL belum diisi');
        }

        try {
            $jawaban = Http::timeout((int) config('services.waha.timeout', 15))
                ->withHeaders(['X-Api-Key' => (string) config('services.waha.key')])
                ->acceptJson()
                ->post("{$url}/api/{$jalur}", $isi);
        } catch (ConnectionException $e) {
            throw new PengirimanGagal('WAHA tidak bisa dihubungi: '.$e->getMessage(), previous: $e);
        }

        if (! $jawaban->successful()) {
            throw new PengirimanGagal("WAHA menolak ({$jawaban->status()}): ".mb_substr($jawaban->body(), 0, 200));
        }

        // WAHA answers with the sent message; its id is "true_<chat>_<id>" or { _serialized }
        $id = $jawaban->json('id');

        return is_array($id) ? (string) ($id['_serialized'] ?? $id['id'] ?? '') : (string) $id;
    }
}
