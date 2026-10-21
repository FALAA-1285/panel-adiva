<?php

namespace App\Jobs;

use App\Bot\PengirimanGagal;
use App\Bot\PengirimWa;
use App\Enums\JenisNotifikasi;
use App\Enums\PengirimPesan;
use App\Enums\StatusBroadcast;
use App\Models\Broadcast;
use App\Models\BroadcastPenerima;
use App\Models\JedaBot;
use App\Models\Notifikasi;
use App\Models\Pesan;
use App\Support\AturanBroadcast;
use App\Support\Demo;
use App\Support\PengaturanToko;
use App\Support\PesanBroadcast;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Sends one campaign message, then queues itself for the next after the random wait
 * ("Aturan kirim aman"): only 08.00–20.00 WIB, at most 300 a day over every campaign,
 * and the campaign pauses itself after 5 failures in a row.
 *
 * Each campaign has one running chain, named by broadcast.giliran; a job from an older
 * chain finds another name there and stops.
 */
class KirimBroadcast implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $broadcastId, public string $giliran, public int $gagalBeruntun = 0) {}

    /**
     * Queue a step of the chain. A sync queue cannot wait, so nothing is queued there
     * (the tests run steps by hand; broadcast:lanjutkan restarts chains on a real queue).
     */
    public static function jadwalkan(int $broadcastId, string $giliran, CarbonInterface $kapan, int $gagalBeruntun = 0): void
    {
        if (Queue::connection() instanceof SyncQueue) {
            return;
        }

        // The demo clock is frozen, so a delayed job would never come due: the stand-in
        // sends the next message at once, and a campaign that must wait longer stays put.
        if (Demo::aktif()) {
            if ($kapan->lte(now()->addMinute())) {
                self::dispatch($broadcastId, $giliran, $gagalBeruntun);
            }

            return;
        }

        self::dispatch($broadcastId, $giliran, $gagalBeruntun)->delay($kapan);
    }

    public function handle(PengirimWa $pengirim): void
    {
        // a chain that was just replaced may still be sending: wait for it, never send twice
        $berikutnya = Cache::lock("broadcast:{$this->broadcastId}", 60)->block(60, fn () => $this->langkah($pengirim));

        if ($berikutnya) {
            self::jadwalkan($this->broadcastId, $this->giliran, ...$berikutnya);
        }
    }

    /**
     * @return array{0: CarbonInterface, 1: int}|null when the next step is due, and the failures in a row so far
     */
    private function langkah(PengirimWa $pengirim): ?array
    {
        $broadcast = Broadcast::query()->find($this->broadcastId);

        if (! $broadcast || $broadcast->giliran !== $this->giliran) {
            return null;
        }

        if ($broadcast->status === StatusBroadcast::Terjadwal) {
            if ($broadcast->jadwal_at?->isFuture()) {
                return $this->lanjut($broadcast, $broadcast->jadwal_at);
            }

            $broadcast->update([
                'jumlah_target' => $broadcast->kunciPenerima(),
                'status' => StatusBroadcast::Mengirim,
                'mulai_at' => now(),
            ]);
        }

        if ($broadcast->status !== StatusBroadcast::Mengirim) {
            return null;
        }

        if (! AturanBroadcast::bolehKirim(now()) || AturanBroadcast::terkirimHariIni() >= AturanBroadcast::BATAS_HARIAN) {
            return $this->lanjut($broadcast, AturanBroadcast::jamBukaBerikutnya(now()), $this->gagalBeruntun);
        }

        $penerima = $broadcast->penerima()->where('status', BroadcastPenerima::MENUNGGU)->orderBy('id')->with('customer')->first();

        if (! $penerima) {
            return $this->selesai($broadcast);
        }

        $gagal = match ($this->kirim($broadcast, $penerima, $pengirim)) {
            true => 0,
            false => $this->gagalBeruntun + 1,
            null => $this->gagalBeruntun,
        };

        if ($gagal >= AturanBroadcast::GAGAL_BERUNTUN) {
            $broadcast->update([
                'status' => StatusBroadcast::Dijeda,
                'catatan' => 'Dijeda otomatis: '.AturanBroadcast::GAGAL_BERUNTUN.' gagal beruntun',
                'berikutnya_at' => null,
            ]);

            return null;
        }

        if (! $broadcast->penerima()->where('status', BroadcastPenerima::MENUNGGU)->exists()) {
            return $this->selesai($broadcast);
        }

        return $this->lanjut($broadcast, now()->addSeconds(AturanBroadcast::jeda($broadcast->kecepatan)), $gagal);
    }

    /**
     * @return bool|null true sent, false failed, null skipped ("Selalu dikecualikan")
     */
    private function kirim(Broadcast $broadcast, BroadcastPenerima $penerima, PengirimWa $pengirim): ?bool
    {
        $customer = $penerima->customer;
        $lewati = match (true) {
            (bool) $customer?->status_optout => 'dilewati: opt-out',
            JedaBot::query()->aktif()->where('chat_id', $penerima->chat_id)->exists() => 'dilewati: sedang dijeda',
            default => null,
        };

        if ($lewati) {
            $penerima->update(['status' => BroadcastPenerima::GAGAL, 'keterangan_gagal' => $lewati]);

            return null;
        }

        $sesi = $penerima->sesi_wa ?: PengaturanToko::nilai('SESI_UTAMA');
        $teks = PesanBroadcast::untuk($broadcast, $customer);
        $gambar = PesanBroadcast::gambar($broadcast->gambar);

        try {
            if (blank($sesi)) {
                throw new PengirimanGagal('nomor bot tidak diketahui');
            }

            $id = $gambar
                ? $pengirim->kirimGambar((string) $sesi, $penerima->chat_id, $gambar['url'], $gambar['mimetype'], $teks)
                : $pengirim->kirimTeks((string) $sesi, $penerima->chat_id, $teks);
        } catch (PengirimanGagal $e) {
            $penerima->update(['status' => BroadcastPenerima::GAGAL, 'keterangan_gagal' => Str::limit($e->getMessage(), 120)]);

            return false;
        }

        $penerima->update(['status' => BroadcastPenerima::TERKIRIM, 'terkirim_at' => now(), 'wa_message_id' => $id, 'sesi_wa' => $sesi]);

        // in the customer's thread too, so a reply like "1" makes sense to whoever reads it
        Pesan::create([
            'crm_customer_id' => $penerima->crm_customer_id,
            'chat_id' => $penerima->chat_id,
            'sesi_wa' => $sesi,
            'waktu' => now(),
            'pengirim' => PengirimPesan::Adiva,
            'teks' => $teks,
            'gambar_produk_id' => $gambar ? $broadcast->gambar_produk_id : null,
            'lampiran_keterangan' => $gambar ? 'Foto produk · '.$broadcast->gambar?->title : null,
            'status_kirim' => 'TERKIRIM',
            'wa_message_id' => $id,
            'broadcast_id' => $broadcast->id,
            'meta' => ['otomatis' => 'BROADCAST'],
        ]);

        return true;
    }

    /**
     * @return array{0: CarbonInterface, 1: int}
     */
    private function lanjut(Broadcast $broadcast, CarbonInterface $kapan, int $gagal = 0): array
    {
        $broadcast->update(['berikutnya_at' => $kapan]);

        return [$kapan, $gagal];
    }

    /**
     * Nobody left to send to: the campaign is done and the supervisor gets the report.
     */
    private function selesai(Broadcast $broadcast): null
    {
        $broadcast->update(['status' => StatusBroadcast::Selesai, 'selesai_at' => now(), 'berikutnya_at' => null]);
        $hasil = Broadcast::query()->denganHitungan()->findOrFail($broadcast->id);

        Notifikasi::create([
            'waktu' => now(),
            'jenis' => JenisNotifikasi::BroadcastSelesai->value,
            'kategori' => JenisNotifikasi::BroadcastSelesai->kategori()->value,
            'penerima' => 'ADMIN_WA',
            'judul' => 'Broadcast selesai',
            'detail' => "{$broadcast->nama} · {$hasil->jumlah_terkirim} terkirim · {$hasil->jumlah_dibalas} balas · {$hasil->jumlah_order} order",
            'broadcast_id' => $broadcast->id,
        ]);

        return null;
    }
}
