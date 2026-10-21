<?php

namespace App\Bot;

use App\Enums\JenisNotifikasi;
use App\Jobs\GabungPesanMasuk;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Riwayat;
use App\Support\CatatPesanMasuk;
use App\Support\KabarBot;
use App\Support\PengaturanAi;
use App\Support\PesanKeluar;
use Illuminate\Support\Facades\DB;

/**
 * What the panel does when WAHA reports a WhatsApp message (n8n's ① Pintu masuk, D1, D2 and C).
 *
 * - an admin typing an order command is pointed to the panel; any other admin message is left alone
 * - an attachment (a transfer proof, a voice note) is matched to the customer's order
 * - a customer's chat checks the pause first (D1): paused, the bot stays quiet; otherwise the
 *   message is kept and, after the merge wait, handed to Adiva together with the ones around it (D2)
 */
class AlurPesanMasuk
{
    private const ARAHKAN = 'Perintah lewat WhatsApp sudah dipindah ke panel Adiva. ACC, jeda, catat order, dan jawaban ke customer sekarang dijalankan dari panel.';

    private const DIJEDA = "Halo kak 🙏\nPesan kakak sudah kami terima dan saat ini sedang dibantu langsung oleh tim kami.\n\nMohon ditunggu sebentar ya kak, nanti kami balas dari chat ini 😊";

    /** The wait for the next message when the setting is empty. */
    private const TUNGGU_DETIK = 8;

    public function __construct(
        private KenaliPengirim $pengirim,
        private WahaChat $waha,
        private PesanKeluar $keluar,
        private PengirimWa $wa,
    ) {}

    public function proses(PesanMasuk $pesan): void
    {
        // WhatsApp often hides a customer's number behind an @lid code; staff are matched by phone number
        $pn = $pesan->lid() ? $this->waha->nomorDariLid($pesan->sesi, $pesan->dari()) : null;
        $pilahan = SaringPesan::pilah($pesan, $this->pengirim->admin($pesan, $pn));

        match ($pilahan->jalur) {
            SaringPesan::PERINTAH_ADMIN => $this->arahkanKePanel($pilahan->pesan),
            SaringPesan::LAMPIRAN => $this->lampiran($pilahan->pesan),
            SaringPesan::CHAT => $this->chat($pilahan, $pn),
            default => null,
        };
    }

    /**
     * D1: filter and check the pause, then D2: keep the message and wait for the next one.
     */
    private function chat(SaringPesan $pilahan, ?string $pn): void
    {
        $pesan = $pilahan->pesan;

        // counted on Monitoring as "Diabaikan"
        if ($pilahan->dibuang()) {
            KabarBot::event('chatbot', 'EKSEKUSI', 'BERHASIL', 'Pesan diabaikan', ['jalur' => 'DIABAIKAN', 'pesan' => 1]);
        }

        if ($pilahan->abaikan || $pesan->dariSaya() || $pesan->grup() || $pesan->status() || $pesan->body() === '') {
            return;
        }

        $nomor = KenaliPengirim::norm($pesan->dari());
        $jeda = JedaBot::query()->aktif()->untuk($nomor, $pesan->dari())->orderByDesc('id')->first();

        if ($jeda === null) {
            $this->tampung($pesan, $pn);

            return;
        }

        if ($jeda->masihBerlaku()) {
            $this->saatDijeda($pesan, $jeda);

            return;
        }

        // the pause ran out: the bot goes on, and the supervisor hears of it
        JedaBot::akhiriKedaluwarsa($nomor, $pesan->dari());
        KabarBot::notifikasi(JenisNotifikasi::JedaKedaluwarsa, 'Jeda kedaluwarsa, bot lanjut', ($jeda->nama ?: $pesan->dari()).' · supervisor dikabari', $this->customer($pesan));
        $this->tampung($pesan, $pn);
    }

    /**
     * A CS holds this customer: the message is only recorded, the customer is told once that a
     * person is helping, and the supervisor is told that the customer wrote.
     */
    private function saatDijeda(PesanMasuk $pesan, JedaBot $jeda): void
    {
        CatatPesanMasuk::dariCustomer($pesan->dari(), $pesan->sesi, $pesan->body(), $pesan->id(), 'B');
        KabarBot::event('chatbot', 'EKSEKUSI', 'BERHASIL', 'Chat saat dijeda', ['jalur' => 'B', 'pesan' => 1], customer: $this->customer($pesan));

        if ($jeda->sudah_diberi_tahu) {
            return;
        }

        try {
            $this->keluar->teks($pesan->sesi, $pesan->dari(), self::DIJEDA);
            $jeda->update(['sudah_diberi_tahu' => true]);
        } catch (PengirimanGagal) {
            // not marked: it is tried again with the next message
        }

        KabarBot::notifikasi(
            JenisNotifikasi::JedaChat,
            'Customer dijeda mengirim chat',
            ($pesan->namaWa() ?: $pesan->dari()).' · '.mb_substr($pesan->body(), 0, 200),
            $this->customer($pesan),
        );
    }

    /**
     * D2, first half: the message goes into the chat and the buffer; the answer is decided when
     * the wait for the next message is over (GabungPesanMasuk).
     */
    private function tampung(PesanMasuk $pesan, ?string $pn): void
    {
        CatatPesanMasuk::dariCustomer($pesan->dari(), $pesan->sesi, $pesan->body(), $pesan->id(), 'D');

        DB::table('buffer_pesan')->insert([
            'chat_id' => $pesan->dari(), 'message_id' => $pesan->id(), 'teks' => $pesan->body(), 'waktu_ms' => (int) (microtime(true) * 1000),
        ]);

        $detik = (int) (PengaturanAi::nilai()['AI_TUNGGU_GABUNG'] ?? 0) ?: self::TUNGGU_DETIK;

        GabungPesanMasuk::dispatch($pesan->keArray(), $pn)->delay(now()->addSeconds($detik));
    }

    /**
     * C: a photo, a file or a voice note. Matched to the customer's order, the supervisor is
     * told (a transfer proof marks the order BUKTI MASUK), and the customer is answered unless a
     * CS holds the chat.
     */
    private function lampiran(PesanMasuk $pesan): void
    {
        $dari = $pesan->dari();
        $nomor = KenaliPengirim::norm($dari);
        $customer = $this->customer($pesan);

        CatatPesanMasuk::dariCustomer($dari, $pesan->sesi, filled(trim($pesan->body())) ? $pesan->body() : '[Lampiran]', $pesan->id(), 'C', $pesan->media()['url'] ?? null, 'Lampiran dari customer');
        KabarBot::event('chatbot', 'EKSEKUSI', 'BERHASIL', 'Lampiran diteruskan', ['jalur' => 'C', 'pesan' => 1], customer: $customer);

        $lampiran = LampiranMasuk::susun($pesan, LogOrder::query()->where('chat_id', $dari)->orderBy('id')->get(), $this->pengirim->labelNomorBot($pesan->sesi));
        $order = $lampiran->order;

        if ($lampiran->tandaiBukti && $order !== null) {
            $order->update(['catatan_admin' => $lampiran->catatanBukti, 'bukti_masuk_at' => $order->bukti_masuk_at ?? now()]);
            Riwayat::create([
                'subjek_type' => $order->getMorphClass(), 'subjek_id' => $order->id, 'waktu' => now(),
                'jenis' => 'CATATAN', 'nada' => 'gray', 'teks' => 'Bukti transfer masuk · ditandai BUKTI MASUK',
            ]);
            KabarBot::notifikasi(JenisNotifikasi::BuktiMasuk, 'Bukti transfer masuk', ($pesan->namaWa() ?: (string) $order->nama_penerima).' · '.$order->order_id, $customer, $order);
        } else {
            $detail = (string) preg_replace('/\n+/', ' · ', (string) preg_replace('/[*_~]/', '', $lampiran->pesanAdmin));
            KabarBot::notifikasi(JenisNotifikasi::LampiranMasuk, 'Lampiran dari customer', mb_substr($detail, 0, 500), $customer);
        }

        if (JedaBot::query()->menahan()->untuk($nomor, $dari)->exists()) {
            return;
        }

        try {
            $this->keluar->teks($pesan->sesi, $dari, $lampiran->pesanCustomer);
        } catch (PengirimanGagal $e) {
            KabarBot::notifikasi(JenisNotifikasi::BalasanGagal, 'Balasan ke customer gagal terkirim', "{$dari} · {$e->getMessage()}", $customer, $order);
        }
    }

    private function arahkanKePanel(PesanMasuk $pesan): void
    {
        try {
            $this->wa->kirimTeks($pesan->sesi, $pesan->dari(), self::ARAHKAN);
        } catch (PengirimanGagal) {
            // an admin who typed a command can read the panel without this reminder
        }
    }

    private function customer(PesanMasuk $pesan): ?CrmCustomer
    {
        return CrmCustomer::query()->where('chat_id', $pesan->dari())->first();
    }
}
