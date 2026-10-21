<?php

namespace App\Bot;

/**
 * Sends a WhatsApp message from one of the bot's numbers (a WAHA session).
 */
interface PengirimWa
{
    /**
     * @return string the WhatsApp message id
     *
     * @throws PengirimanGagal
     */
    public function kirimTeks(string $sesi, string $chatId, string $teks): string;

    /**
     * A photo with the text as its caption (a campaign with a product photo).
     *
     * @return string the WhatsApp message id
     *
     * @throws PengirimanGagal
     */
    public function kirimGambar(string $sesi, string $chatId, string $url, string $mimetype, string $caption): string;

    /**
     * A file kept in memory (the invoice PDF) with the text as its caption.
     *
     * @return string the WhatsApp message id
     *
     * @throws PengirimanGagal
     */
    public function kirimBerkas(string $sesi, string $chatId, string $isi, string $mimetype, string $namaFile, string $caption): string;
}
