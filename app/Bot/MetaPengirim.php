<?php

namespace App\Bot;

use App\Support\PengaturanToko;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta's Graph API with the page token saved on the Instagram & Facebook page
 * (META_PAGE_TOKEN): POST /me/messages, a reply within Meta's 24-hour window.
 */
class MetaPengirim implements PengirimSosmed
{
    public const GRAPH = 'https://graph.facebook.com/v23.0';

    public function kirimTeks(string $platform, string $idPenerima, string $teks): string
    {
        $jawaban = $this->panggil(fn () => Http::withToken($this->token())->timeout(20)->acceptJson()->post(self::GRAPH.'/me/messages', [
            'recipient' => ['id' => $idPenerima],
            // Instagram takes no messaging_type
            ...($platform === 'FB' ? ['messaging_type' => 'RESPONSE'] : []),
            'message' => ['text' => $teks],
        ]));

        return (string) $jawaban->json('message_id');
    }

    public function cekSambungan(): string
    {
        $jawaban = $this->panggil(fn () => Http::withToken($this->token())->timeout(15)->acceptJson()->get(self::GRAPH.'/me', ['fields' => 'id,name']));

        return trim($jawaban->json('name').' ('.$jawaban->json('id').')');
    }

    private function token(): string
    {
        $token = (string) PengaturanToko::nilai('META_PAGE_TOKEN');

        if ($token === '') {
            throw new PengirimanGagal('Page access token Meta belum diisi');
        }

        return $token;
    }

    /**
     * @param  callable(): Response  $kirim
     */
    private function panggil(callable $kirim): Response
    {
        try {
            $jawaban = $kirim();
        } catch (ConnectionException $e) {
            throw new PengirimanGagal('Meta tidak bisa dihubungi: '.$e->getMessage(), previous: $e);
        }

        if (! $jawaban->successful()) {
            $pesan = (string) ($jawaban->json('error.message') ?? mb_substr($jawaban->body(), 0, 200));

            // code 10, subcode 2018278: the customer last wrote more than 24 hours ago
            throw new PengirimanGagal($jawaban->json('error.code') === 10 || $jawaban->json('error.error_subcode') === 2018278
                ? 'Di luar jendela 24 jam Meta: balas dari aplikasi Instagram/Facebook, atau tunggu customer menulis lagi'
                : "Meta menolak ({$jawaban->status()}): {$pesan}");
        }

        return $jawaban;
    }
}
