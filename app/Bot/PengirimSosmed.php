<?php

namespace App\Bot;

/**
 * Sends an Instagram / Facebook message from the shop's page (Graph API), and checks the
 * connection the panel's Instagram & Facebook page shows.
 */
interface PengirimSosmed
{
    /**
     * @param  'IG'|'FB'  $platform
     * @return string Meta's message id (mid)
     *
     * @throws PengirimanGagal
     */
    public function kirimTeks(string $platform, string $idPenerima, string $teks): string;

    /**
     * The page the token belongs to, e.g. "Bang Bana (1234567890)".
     *
     * @throws PengirimanGagal
     */
    public function cekSambungan(): string;
}
