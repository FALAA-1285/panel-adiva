<?php

namespace App\Bot;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stands in for WAHA (demo mode, the tests, or before WAHA is connected): nothing is
 * sent, the message is only written to the log.
 */
class TiruanPengirim implements PengirimWa
{
    public function kirimTeks(string $sesi, string $chatId, string $teks): string
    {
        Log::info('WhatsApp tiruan (tidak dikirim)', ['sesi' => $sesi, 'chat_id' => $chatId, 'teks' => $teks]);

        return 'tiruan-'.Str::lower(Str::random(12));
    }

    public function kirimGambar(string $sesi, string $chatId, string $url, string $mimetype, string $caption): string
    {
        Log::info('WhatsApp tiruan (tidak dikirim)', ['sesi' => $sesi, 'chat_id' => $chatId, 'gambar' => $url, 'teks' => $caption]);

        return 'tiruan-'.Str::lower(Str::random(12));
    }

    public function kirimBerkas(string $sesi, string $chatId, string $isi, string $mimetype, string $namaFile, string $caption): string
    {
        Log::info('WhatsApp tiruan (tidak dikirim)', ['sesi' => $sesi, 'chat_id' => $chatId, 'berkas' => $namaFile, 'ukuran' => strlen($isi), 'teks' => $caption]);

        return 'tiruan-'.Str::lower(Str::random(12));
    }
}
