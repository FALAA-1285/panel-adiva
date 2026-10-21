<?php

namespace App\Bot;

use App\Enums\AksiPesanan;
use App\Enums\JenisNotifikasi;
use App\Enums\KategoriAktivitas;
use App\Enums\TahapFunnel;
use App\Jobs\BuatPengirimanMengantar;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Models\User;
use App\Support\Aktivitas;
use App\Support\KabarBot;
use App\Support\PengaturanToko;
use App\Support\PerintahPesanan;
use App\Support\PesanKeluar;
use App\Support\PesanPerintah;
use App\Support\SimpanCrm;

/**
 * Runs an order command in the panel itself (BOT_DRIVER=lokal) instead of through n8n.
 *
 * The order changes first, in one transaction (PerintahPesanan::jalankan: status, invoice
 * number, history, activity log). What follows reaches outside and never undoes it: the
 * invoice PDF (ACC), the message to the customer, the CRM (SELESAI, TUTUP) and the shipment
 * at Mengantar (LUNAS, KIRIM). A message WhatsApp refuses is a notice to the supervisor, not
 * a failed command.
 */
class LokalPerintahOrder implements PerintahOrder
{
    public function __construct(
        private PesanKeluar $pesan,
        private PengirimInvoice $invoice,
        private PengirimanMengantar $mengantar,
        private LaporPengiriman $lapor,
    ) {}

    public function jalankan(AksiPesanan $aksi, LogOrder $order, User $oleh, ?string $catatan = null): void
    {
        $catatan = filled($catatan) ? trim((string) $catatan) : null;
        $order->load('items');

        $this->periksa($aksi, $order, $catatan);

        if ($aksi === AksiPesanan::BatalKirim) {
            $this->batalKirim($order, $oleh);

            return;
        }

        // KIRIM without a waybill: the bot books the shipment, which takes a while
        if ($aksi === AksiPesanan::Kirim && $catatan === null && $this->bookingMengantar($order)) {
            Aktivitas::catat($oleh, KategoriAktivitas::Pesanan, 'KIRIM order', "{$order->order_id} · membuat order pengiriman Mengantar", $order);
            BuatPengirimanMengantar::dispatch($order->id);
            $this->catatEksekusi($order);

            return;
        }

        // the words are chosen from the order as it is now: BATAL, for one, depends on the old status
        $pesanCustomer = PesanPerintah::untukCustomer($aksi, $order, $catatan);
        $revisi = PerintahPesanan::revisiOngkir($aksi, $order, PerintahPesanan::ongkir($catatan));

        PerintahPesanan::jalankan($aksi, $order, $oleh, $catatan);
        $order->refresh()->load('items');

        match ($aksi) {
            AksiPesanan::Acc => $this->invoice->kirim($order, $oleh, $revisi),
            AksiPesanan::Selesai => SimpanCrm::penutupan($order, TahapFunnel::OrderDibuat, "transaksi {$order->order_id} selesai"),
            AksiPesanan::Tutup => SimpanCrm::penutupan($order, null, "order {$order->order_id} ditutup tanpa chat ke customer".($catatan !== null ? ' - '.$this->rapi($catatan) : '')),
            default => null,
        };

        if ($pesanCustomer !== null) {
            $this->kabari($order, $pesanCustomer);
        }

        // an order sent by courier is booked as soon as it is paid
        if ($aksi === AksiPesanan::Lunas && $this->bookingMengantar($order)) {
            BuatPengirimanMengantar::dispatch($order->id);
        }

        $this->catatEksekusi($order);
    }

    /**
     * What stops a command that the status rules (PerintahPesanan::alasanTidakBerlaku) let through.
     *
     * @throws PerintahGagal
     */
    private function periksa(AksiPesanan $aksi, LogOrder $order, ?string $catatan): void
    {
        $sendiri = PerintahPesanan::kurirSendiri($order);

        if ($aksi === AksiPesanan::Acc) {
            $bayarDiTempat = PesanPerintah::ambilSendiri($order) && ! PesanPerintah::potongGaji($order) && ! preg_match('/TRANSFER/i', $order->kurir_pilihan.' '.$order->metode_bayar);

            if (! $bayarDiTempat && blank(PengaturanToko::nilai('REKENING'))) {
                throw new PerintahGagal('Rekening belum diisi. Isi dulu di panel (Pengaturan → Rekening), baru jalankan ACC lagi.');
            }

            if ($sendiri !== null && PerintahPesanan::ongkir($catatan) === null && (int) $order->ongkir === 0) {
                throw new PerintahGagal($sendiri === 'armada'
                    ? "Order {$order->order_id} dikirim ARMADA KANTOR, tapi biaya kirimnya belum ditetapkan. Tulis saat ACC, contoh: 500000, atau gratis kalau biaya kirim ditanggung kantor."
                    : "Order {$order->order_id} dikirim KURIR MOTOR, tapi ongkirnya belum tercatat. Tulis ongkirnya saat ACC, contoh: 30000");
            }
        }

        // with the booking switched off the waybill has to be typed
        if ($aksi === AksiPesanan::Kirim && $catatan === null && $sendiri === null && ! $order->ambil_sendiri && PengirimanMengantar::mode() === 'MATI') {
            throw new PerintahGagal('Nomor resinya belum disertakan. Contoh: JNE1234567890');
        }
    }

    /**
     * Whether the bot books this order's shipment at Mengantar: sent by a courier it books
     * (not pickup, not the company's own, not an employee order), not yet with a waybill,
     * and the switch is not off.
     */
    private function bookingMengantar(LogOrder $order): bool
    {
        return ! $order->ambil_sendiri
            && PerintahPesanan::kurirSendiri($order) === null
            && ! PesanPerintah::potongGaji($order)
            && PengirimanMengantar::mode() !== 'MATI'
            && trim((string) $order->no_resi) === '';
    }

    /**
     * BATAL KIRIM: cancel the booking at Mengantar first, and only then change the order.
     *
     * @throws PerintahGagal
     */
    private function batalKirim(LogOrder $order, User $oleh): void
    {
        $hasil = $this->mengantar->batal($order);

        if (! $hasil->ok) {
            $this->lapor->terapkan($order, $hasil);

            throw new PerintahGagal($hasil->alasan !== '' ? $hasil->alasan : 'Order pengiriman Mengantar belum bisa dibatalkan.');
        }

        PerintahPesanan::jalankan(AksiPesanan::BatalKirim, $order, $oleh);
        $this->lapor->terapkan($order->refresh(), $hasil);
        $this->catatEksekusi($order);
    }

    /**
     * Tells the customer; a message WhatsApp refuses becomes a notice, not a failed command.
     */
    private function kabari(LogOrder $order, string $pesan): void
    {
        try {
            $this->pesan->teks(PesanKeluar::sesi($order->sesi_wa), (string) $order->chat_id, $pesan);
        } catch (PengirimanGagal $e) {
            KabarBot::notifikasi(
                JenisNotifikasi::BalasanGagal,
                'Balasan ke customer gagal terkirim',
                "{$order->nama_penerima} · {$order->order_id} · {$e->getMessage()}",
                CrmCustomer::query()->where('chat_id', $order->chat_id)->first(),
                $order,
            );
        }
    }

    /**
     * Monitoring counts a command run by an admin as one run of path A.
     */
    private function catatEksekusi(LogOrder $order): void
    {
        KabarBot::event('chatbot', 'EKSEKUSI', 'BERHASIL', 'Perintah admin', ['jalur' => 'A', 'pesan' => 1, 'sumber' => 'panel'], order: $order);
    }

    private function rapi(string $catatan): string
    {
        return trim((string) preg_replace('/^[\s|:,;-]+|[\s|:,;-]+$/', '', $catatan));
    }
}
