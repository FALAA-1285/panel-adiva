<?php

namespace App\Bot;

/**
 * Staff codes through the n8n gateway (GerbangWaha). Only text: photos and files still need
 * the panel to reach WAHA itself.
 */
class GerbangWahaPengirim implements PengirimWa
{
    public function __construct(private GerbangWaha $gerbang) {}

    public function kirimTeks(string $sesi, string $chatId, string $teks): string
    {
        return $this->gerbang->kirimTeks($sesi, $chatId, $teks);
    }

    public function kirimGambar(string $sesi, string $chatId, string $url, string $mimetype, string $caption): string
    {
        throw new PengirimanGagal('Gerbang WAHA hanya mengirim teks');
    }

    public function kirimBerkas(string $sesi, string $chatId, string $isi, string $mimetype, string $namaFile, string $caption): string
    {
        throw new PengirimanGagal('Gerbang WAHA hanya mengirim teks');
    }
}
