<?php

namespace App\Jobs;

use App\Bot\CatatOrderAdiva;
use App\Bot\KenaliPengirim;
use App\Bot\PengirimanGagal;
use App\Bot\WahaChat;
use App\Enums\JenisNotifikasi;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Support\KabarBot;
use App\Support\PesanKeluar;
use App\Support\SimpanCrm;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Adiva's answer reaches the customer, and what she decided is written down: n8n's D5 (send the
 * bubbles, the buttons, the product photo) and D6 (the CRM, the order, the notices).
 *
 * The CRM and the order are written even when WhatsApp refuses the message, as they always were;
 * the supervisor is then told to send it by hand.
 */
class KirimBalasanAdiva implements ShouldQueue
{
    use Queueable;

    /** A second try would send the customer the same answer twice. */
    public int $tries = 1;

    public int $timeout = 180;

    /**
     * @param  array<string, mixed>  $rb  what RapikanBalasan made
     */
    public function __construct(public array $rb, public ?string $balasanLama = null) {}

    public function handle(PesanKeluar $keluar, WahaChat $waha, CatatOrderAdiva $order): void
    {
        $rb = $this->rb;
        $sesi = PesanKeluar::sesi($rb['session'] ?? null);
        $chat = (string) $rb['chatId'];
        $customer = CrmCustomer::query()->where('chat_id', $chat)->first();

        // an auto-reply from the customer's WhatsApp Business is not answered, only remembered
        if ($rb['perlu_dibalas'] === false) {
            $waha->berhentiMengetik($sesi, $chat);
            $this->simpan($rb, $order, $customer);

            return;
        }

        // a CS may have taken the customer while Adiva was thinking
        if (JedaBot::query()->menahan()->untuk(KenaliPengirim::norm($chat), $chat)->exists()) {
            $waha->berhentiMengetik($sesi, $chat);
            $this->simpan($rb, $order, $customer);

            return;
        }

        $gagal = null;

        try {
            // a long answer comes in up to three bubbles, with "typing…" in between
            foreach ($rb['gelembung_awal'] as $i => $gelembung) {
                $keluar->teks($sesi, $chat, (string) $gelembung, 'D');
                $waha->mengetik($sesi, $chat);
                $berikut = $rb['gelembung_awal'][$i + 1] ?? $rb['balasan_kirim'];
                sleep(min(4, max(1, (int) round(mb_strlen((string) $berikut) / 70))));
            }

            $keluar->teks($sesi, $chat, (string) $rb['balasan_kirim'], 'D', $rb['pilihan_balasan'] !== [] ? ['tombol' => $rb['pilihan_balasan']] : []);
        } catch (PengirimanGagal $e) {
            $gagal = $e;
        }

        if ($gagal === null && $rb['kirim_gambar'] && filled($rb['url_gambar'])) {
            try {
                $keluar->gambar($sesi, self::chatGambar($rb), (string) $rb['url_gambar'], 'Foto produk');
            } catch (PengirimanGagal) {
                // the photo is extra: the answer itself did reach the customer
            }
        }

        $this->simpan($rb, $order, $customer);

        if ($gagal !== null) {
            KabarBot::notifikasi(
                JenisNotifikasi::BalasanGagal,
                'Balasan gagal terkirim',
                mb_substr("{$rb['nama']} ({$rb['kontak']}) · ".(string) $rb['balasan'].' · '.$gagal->getMessage(), 0, 300),
                $customer,
            );
            KabarBot::event('chatbot', 'INSIDEN', 'PERLU_TINDAKAN', 'Balasan gagal terkirim', [], "{$rb['nama']} · WAHA menolak pesan", $customer);
        }
    }

    /**
     * The CRM, the order and the line on Monitoring.
     */
    /**
     * @param  array<string, mixed>  $rb
     */
    private function simpan(array $rb, CatatOrderAdiva $order, ?CrmCustomer $customer): void
    {
        $customer = SimpanCrm::penilaian($rb, $this->balasanLama) ?? $customer;

        if ($rb['butuh_admin'] === 'YA') {
            KabarBot::notifikasi(
                JenisNotifikasi::ButuhAdmin,
                'Butuh admin: '.mb_substr((string) $rb['catatan'], 0, 80),
                (filled($rb['nama']) ? $rb['nama'] : $rb['chatId']).' · '.mb_substr((string) $rb['ringkasan_terakhir'], 0, 200),
                $customer,
            );
        }

        if ($rb['order_siap']) {
            $order->catat($rb, $customer, now()->getTimestampMs());
        }

        KabarBot::event('chatbot', 'EKSEKUSI', 'BERHASIL', 'Pesan dijawab', [
            'jalur' => 'D', 'pesan' => $rb['jumlah_pesan'] ?? 1, 'model' => $rb['model'] ?? '',
            'ongkir' => filled($rb['kurir_pilihan']), 'diteruskan' => $rb['butuh_admin'] === 'YA',
        ], customer: $customer);
    }

    /**
     * The photo goes to the customer's phone number when it is one, else to the chat id.
     *
     * @param  array<string, mixed>  $rb
     */
    private static function chatGambar(array $rb): string
    {
        $d = KenaliPengirim::norm((string) $rb['nomor_wa']);

        return preg_match('/^628\d{7,11}$/', $d) && $d !== explode('@', (string) $rb['chatId'])[0] ? $d.'@c.us' : (string) $rb['chatId'];
    }

    public function failed(?Throwable $galat): void
    {
        KabarBot::notifikasi(
            JenisNotifikasi::BalasanGagal,
            'Balasan Adiva gagal diproses',
            mb_substr(((string) ($this->rb['chatId'] ?? '')).' · '.(string) $galat?->getMessage(), 0, 300),
            CrmCustomer::query()->where('chat_id', (string) ($this->rb['chatId'] ?? ''))->first(),
        );
    }
}
