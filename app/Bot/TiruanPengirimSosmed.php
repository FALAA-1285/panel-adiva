<?php

namespace App\Bot;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stands in for Meta (demo mode, the tests, or before the page is connected): nothing is
 * sent, the message is only written to the log.
 */
class TiruanPengirimSosmed implements PengirimSosmed
{
    public function kirimTeks(string $platform, string $idPenerima, string $teks): string
    {
        Log::info('Pesan sosmed tiruan (tidak dikirim)', ['platform' => $platform, 'id' => $idPenerima, 'teks' => $teks]);

        return 'tiruan-'.Str::lower(Str::random(12));
    }

    public function cekSambungan(): string
    {
        return 'Mode tiruan: tidak tersambung ke Meta';
    }
}
