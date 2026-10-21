<?php

namespace App\Jobs;

use App\Bot\PengirimanGagal;
use App\Bot\PesanMasuk;
use App\Bot\WahaChat;
use App\Enums\JenisNotifikasi;
use App\Models\CrmCustomer;
use App\Support\KabarBot;
use App\Support\PesanKeluar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Adiva could not answer, even with a fresh memory (n8n's 🟥 D5b). The customer is not left in
 * silence: an apology goes out, the supervisor is asked to answer by hand, and the incident is
 * written to Monitoring.
 */
class BalasanGagalAdiva implements ShouldQueue
{
    use Queueable;

    private const MAAF = 'Mohon maaf ya Kak, balasannya sempat tertunda karena chat kami lagi ramai banget. Pesan Kakak sudah kami terima kok, dan akan kami bantu jawab sebentar lagi ya 🙏';

    public int $tries = 1;

    /**
     * @param  array{session: string, payload: array<string, mixed>}  $pesan
     * @param  string  $galat  what the agent said when it gave up
     */
    public function __construct(public array $pesan, public string $galat = '') {}

    public function handle(PesanKeluar $keluar, WahaChat $waha): void
    {
        $pesan = new PesanMasuk($this->pesan['session'], $this->pesan['payload']);
        $chat = $pesan->dari();
        $sesi = PesanKeluar::sesi($pesan->sesi);
        $customer = CrmCustomer::query()->where('chat_id', $chat)->first();
        $waha->berhentiMengetik($sesi, $chat);
        $terkirim = true;

        try {
            $keluar->teks($sesi, $chat, self::MAAF, 'D');
        } catch (PengirimanGagal) {
            $terkirim = false;
        }

        // the running out of the AI's quota is the usual cause, and reads like nothing else
        $kuota = (bool) preg_match('/429|quota|exhaust|rate.?limit|too many/i', $this->galat);

        KabarBot::notifikasi(
            JenisNotifikasi::BalasanGagal,
            'Adiva gagal menjawab, pesan maaf dikirim',
            mb_substr(($pesan->namaWa() ?: $chat).' · "'.mb_substr($pesan->body(), 0, 200).'" · '
                .($terkirim ? 'Customer sudah dikirimi pesan maaf (balasan tertunda).' : '❗ Pesan maaf ke customer juga GAGAL terkirim.')
                .($kuota ? ' 🔋 Penyebab: batas pemakaian AI (Gemini) tercapai. Cek kuota di Google AI Studio.' : '')
                .($this->galat !== '' ? ' Detail: '.$this->galat : ''), 0, 300),
            $customer,
        );
        KabarBot::event('chatbot', 'INSIDEN', 'TERTANGANI', 'Adiva gagal menjawab', ['jenis' => 'AI_GAGAL_TOTAL'], 'Pesan maaf dikirim ke '.($pesan->namaWa() ?: $chat), $customer);
        KabarBot::event('chatbot', 'EKSEKUSI', 'ERROR', 'Error tertangani', ['jalur' => 'D', 'pesan' => 1], customer: $customer);
    }
}
