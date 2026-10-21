<?php

namespace App\Bot;

use App\Support\NomorBot;
use App\Support\NomorWa;
use App\Support\PengaturanToko;

/**
 * WhatsApp to a staff member (sign-up and reset codes, a campaign test, a notice to the
 * supervisor), sent right away while they wait: from the chosen bot number, else
 * SESI_UTAMA, else the first bot number.
 */
class KirimKeStaf
{
    public function __construct(private PengirimWa $pengirim) {}

    /**
     * @return string the WhatsApp message id
     *
     * @throws PengirimanGagal
     */
    public function kirim(string $nomor, string $teks, ?string $sesi = null): string
    {
        return $this->pengirim->kirimTeks($this->sesi($sesi), NomorWa::chatId($nomor), $teks);
    }

    /**
     * @return string the WhatsApp message id
     *
     * @throws PengirimanGagal
     */
    public function kirimGambar(string $nomor, string $url, string $mimetype, string $caption, ?string $sesi = null): string
    {
        return $this->pengirim->kirimGambar($this->sesi($sesi), NomorWa::chatId($nomor), $url, $mimetype, $caption);
    }

    private function sesi(?string $sesi): string
    {
        $sesi = filled($sesi) ? $sesi : (PengaturanToko::nilai('SESI_UTAMA') ?: array_key_first(NomorBot::daftar()));

        if (blank($sesi)) {
            throw new PengirimanGagal('Nomor bot belum diatur (SESI_UTAMA kosong)');
        }

        return (string) $sesi;
    }
}
