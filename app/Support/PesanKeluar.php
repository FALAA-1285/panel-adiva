<?php

namespace App\Support;

use App\Bot\PengirimanGagal;
use App\Bot\PengirimWa;
use App\Enums\PengirimPesan;
use App\Models\CrmCustomer;
use App\Models\Pesan;

/**
 * The one way the bot sends something to a customer: through WAHA, then the line goes
 * into the chat (Percakapan). It replaces every "Kirim …" node and the "Catat: Kirim …"
 * node that followed it in n8n. Nothing is written when WAHA refuses.
 */
class PesanKeluar
{
    public function __construct(private PengirimWa $wa) {}

    /**
     * The bot number a customer's messages go out from: the one they last used, else the main one.
     */
    public static function sesi(?string $sesiWa): string
    {
        return filled($sesiWa) ? (string) $sesiWa : (string) PengaturanToko::nilai('SESI_UTAMA');
    }

    /**
     * @param  array<string, mixed>  $meta
     *
     * @throws PengirimanGagal
     */
    public function teks(string $sesi, string $chatId, string $teks, ?string $jalur = null, array $meta = []): Pesan
    {
        $id = $this->wa->kirimTeks($sesi, $chatId, $teks);
        $tombol = is_array($meta['tombol'] ?? null) ? array_values(array_map('strval', $meta['tombol'])) : null;
        unset($meta['tombol']);

        return $this->catat($sesi, $chatId, $teks, $id, $jalur, $meta, tombol: $tombol);
    }

    /**
     * A product photo (the AI asked for one).
     *
     * @throws PengirimanGagal
     */
    public function gambar(string $sesi, string $chatId, string $url, string $keterangan, string $caption = ''): Pesan
    {
        $id = $this->wa->kirimGambar($sesi, $chatId, $url, 'image/jpeg', $caption);

        return $this->catat($sesi, $chatId, $caption, $id, null, [], $keterangan);
    }

    /**
     * @throws PengirimanGagal
     */
    public function berkas(string $sesi, string $chatId, string $isi, string $mimetype, string $namaFile, string $caption, string $keterangan): Pesan
    {
        $id = $this->wa->kirimBerkas($sesi, $chatId, $isi, $mimetype, $namaFile, $caption);

        return $this->catat($sesi, $chatId, $caption, $id, null, [], $keterangan);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>|null  $tombol  the replies offered at the end of the message
     */
    private function catat(string $sesi, string $chatId, string $teks, string $idWa, ?string $jalur, array $meta, ?string $lampiran = null, ?array $tombol = null): Pesan
    {
        // a message id already stored is the same message (WAHA may echo it back)
        if ($idWa !== '' && ($ada = Pesan::query()->where('wa_message_id', $idWa)->first()) !== null) {
            return $ada;
        }

        return Pesan::create([
            'crm_customer_id' => CrmCustomer::query()->where('chat_id', $chatId)->value('id'),
            'chat_id' => $chatId,
            'sesi_wa' => $sesi,
            'waktu' => now(),
            'pengirim' => PengirimPesan::Adiva,
            'teks' => $teks,
            'lampiran_keterangan' => $lampiran,
            'tombol' => $tombol !== null && $tombol !== [] ? $tombol : null,
            'jalur' => $jalur,
            'status_kirim' => 'TERKIRIM',
            'wa_message_id' => $idWa !== '' ? $idWa : null,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
