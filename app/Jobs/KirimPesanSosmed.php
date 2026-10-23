<?php

namespace App\Jobs;

use App\Bot\PengirimSosmed;
use App\Models\SosmedPesan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends an admin's reply written on the Instagram & Facebook page through Meta, and
 * records whether it went out.
 */
class KirimPesanSosmed implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public SosmedPesan $pesan) {}

    /**
     * Wait before trying again: 10 seconds, then a minute.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(PengirimSosmed $pengirim): void
    {
        $pesan = $this->pesan;

        if ($pesan->status_kirim === 'TERKIRIM') {
            return;
        }

        $kontak = $pesan->kontak;
        if (! $kontak) {
            return;
        }

        $mid = $pengirim->kirimTeks($kontak->platform === 'FB' ? 'FB' : 'IG', $kontak->id_pengirim, (string) $pesan->teks);

        // Meta's echo may have been logged by n8n already (META_APP_ID not set there): keep this one
        SosmedPesan::query()->where('mid', $mid)->whereKeyNot($pesan->id)->delete();
        $pesan->update(['status_kirim' => 'TERKIRIM', 'mid' => $mid]);
    }

    /**
     * Every try failed: the reply stays in the thread, marked failed with Meta's reason.
     */
    public function failed(?Throwable $galat): void
    {
        $this->pesan->update([
            'status_kirim' => 'GAGAL',
            'meta' => [...$this->pesan->meta ?? [], 'galat' => $galat?->getMessage()],
        ]);
    }
}
