<?php

namespace App\Jobs;

use App\Bot\PengirimanGagal;
use App\Bot\PengirimWa;
use App\Models\Pesan;
use App\Support\PengaturanToko;
use App\Support\PesanBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a chat line written in the panel (an admin's reply) through WhatsApp, from
 * the bot number the customer last used, and records whether it went out.
 */
class KirimPesanWa implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public Pesan $pesan) {}

    /**
     * Wait before trying again: 10 seconds, then a minute.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(PengirimWa $pengirim): void
    {
        $pesan = $this->pesan;

        if ($pesan->status_kirim === 'TERKIRIM') {
            return;
        }

        $sesi = $pesan->sesi_wa ?? $pesan->customer->sesi_wa ?? PengaturanToko::nilai('SESI_UTAMA');

        // trying again will not find a number: mark it failed now
        if (blank($sesi)) {
            $this->fail(new PengirimanGagal('Nomor bot untuk customer ini tidak diketahui (SESI_UTAMA kosong)'));

            return;
        }

        // "Lampirkan gambar produk": the photo with the text as its caption
        $gambar = PesanBroadcast::gambar($pesan->gambar);
        $id = $gambar
            ? $pengirim->kirimGambar((string) $sesi, $pesan->chat_id, $gambar['url'], $gambar['mimetype'], (string) $pesan->teks)
            : $pengirim->kirimTeks((string) $sesi, $pesan->chat_id, (string) $pesan->teks);

        $pesan->update(['status_kirim' => 'TERKIRIM', 'wa_message_id' => $id, 'sesi_wa' => $sesi]);
    }

    /**
     * Every try failed: the reply stays in the thread, marked to be sent again.
     */
    public function failed(?Throwable $galat): void
    {
        $this->pesan->update([
            'status_kirim' => 'GAGAL',
            'meta' => [...$this->pesan->meta ?? [], 'galat' => $galat?->getMessage()],
        ]);
    }
}
