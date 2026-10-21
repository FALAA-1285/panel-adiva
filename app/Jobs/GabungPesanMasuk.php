<?php

namespace App\Jobs;

use App\Bot\JawabanAdiva;
use App\Bot\KenaliPengirim;
use App\Bot\N8nWebhook;
use App\Bot\PerintahGagal;
use App\Bot\PesanMasuk;
use App\Bot\RapikanBalasan;
use App\Bot\WahaChat;
use App\Enums\JenisNotifikasi;
use App\Models\CrmCustomer;
use App\Support\KabarBot;
use App\Support\PengaturanToko;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * D2, second half: the wait for the next message is over. Only the job of the *last* message
 * goes on; it takes everything the customer wrote meanwhile as one message, ticks it read,
 * shows "typing…", and hands it to Adiva in n8n.
 */
class GabungPesanMasuk implements ShouldQueue
{
    use Queueable;

    /** A message older than this left in the buffer is the leftover of a run that died. */
    private const UMUR_BUFFER_MS = 5 * 60 * 1000;

    public int $tries = 1;

    /**
     * @param  array{session: string, payload: array<string, mixed>}  $pesan
     */
    public function __construct(public array $pesan, public ?string $pn = null) {}

    public function handle(N8nWebhook $n8n, WahaChat $waha, KenaliPengirim $pengirim): void
    {
        $pesan = new PesanMasuk($this->pesan['session'], $this->pesan['payload']);
        $chat = $pesan->dari();
        $sekarangMs = (int) (microtime(true) * 1000);

        $baris = DB::table('buffer_pesan')->where('chat_id', $chat)->orderBy('id')->get()
            ->filter(fn ($r) => $r->message_id !== null && $r->waktu_ms > 0 && $sekarangMs - $r->waktu_ms <= self::UMUR_BUFFER_MS)
            ->sort(fn ($a, $b) => [$a->waktu_ms, $a->message_id] <=> [$b->waktu_ms, $b->message_id])
            ->values();

        // an empty buffer: another run has taken this chat's messages already
        if ($baris->isEmpty()) {
            return;
        }

        // a later message is waiting: its own job will answer them all
        if ((string) $baris->last()->message_id !== $pesan->id()) {
            return;
        }

        $gabung = $baris->map(fn ($r) => trim((string) $r->teks))->filter(fn ($t) => $t !== '')->implode("\n");

        // only what was merged: a message that arrives while this one is handed over stays for its own job
        DB::table('buffer_pesan')->where('chat_id', $chat)->where('id', '<=', $baris->max('id'))->delete();
        DB::table('buffer_pesan')->where('waktu_ms', '<', $sekarangMs - 600_000)->delete();

        $waha->tandaiDibaca($pesan->sesi, $chat, $pesan->id());
        $waha->mengetik($pesan->sesi, $chat);

        try {
            // n8n hands back Adiva's answer; if it does not answer at all, try again twice
            $jawaban = retry([1000, 3000], fn () => $n8n->kirim('PESAN_MASUK', [
                // the same message sent again (a retry after a timeout) is answered once
                'request_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'wa:'.$pesan->id())->toString(),
                'chat_id' => $chat,
                'nomor_wa' => explode('@', $chat)[0],
                'nama_wa' => $pesan->namaWa(),
                'pesan' => $gabung !== '' ? $gabung : $pesan->body(),
                'message_id' => $pesan->id(),
                'session' => $pesan->sesi,
                // not "waktu": the webhook already stamps its own call with that name
                'waktu_pesan' => now()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                'jumlah_pesan' => $baris->count(),
                'pn' => $this->pn,
                'label_nomor_bot' => $pengirim->labelNomorBot($pesan->sesi),
                'sarah_wa' => PengaturanToko::nilai('SARAH_WA'),
            ], (int) config('services.n8n.timeout_jawab', 180)));

            $hasil = JawabanAdiva::dariN8n($jawaban);

            if ($hasil->gagal) {
                BalasanGagalAdiva::dispatch($pesan->keArray(), $hasil->galat);

                return;
            }

            KirimBalasanAdiva::dispatch(
                RapikanBalasan::rapikan($hasil->agen, $hasil->src, $hasil->orders, $hasil->config, now()->getTimestampMs()),
                (string) ($hasil->src['data_lama']['balasan_terakhir_bot'] ?? ''),
            );
        } catch (PerintahGagal $e) {
            $waha->berhentiMengetik($pesan->sesi, $chat);
            Log::warning('Pesan customer belum diteruskan ke Adiva: '.$e->getMessage());
            KabarBot::notifikasi(
                JenisNotifikasi::BalasanGagal,
                'Pesan customer belum sampai ke Adiva',
                "{$chat} · ".mb_substr($gabung, 0, 160).' · '.$e->getMessage(),
                CrmCustomer::query()->where('chat_id', $chat)->first(),
            );
        }
    }

    public function failed(?Throwable $galat): void
    {
        $pesan = new PesanMasuk($this->pesan['session'], $this->pesan['payload']);

        KabarBot::notifikasi(
            JenisNotifikasi::WorkflowError,
            'Pesan masuk gagal diproses',
            "{$pesan->dari()} · ".mb_substr($pesan->body(), 0, 120).' · '.mb_substr((string) $galat?->getMessage(), 0, 160),
        );
    }
}
