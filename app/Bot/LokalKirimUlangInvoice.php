<?php

namespace App\Bot;

use App\Models\LogOrder;
use App\Models\User;
use App\Support\PesanKeluar;

/**
 * "Kirim ulang ke customer" without n8n: the PDF kept at ACC goes out again, from the bot
 * number the customer uses.
 */
class LokalKirimUlangInvoice implements KirimUlangInvoice
{
    public function __construct(private PesanKeluar $pesan) {}

    public function kirim(LogOrder $order, User $oleh): void
    {
        $pdf = $order->invoicePdf;

        if ($pdf === null) {
            throw new PerintahGagal('PDF invoice belum dibuat. ACC lagi dengan ongkir yang sama (revisi ongkir) untuk membuatnya.');
        }

        try {
            $this->pesan->berkas(
                PesanKeluar::sesi($order->sesi_wa),
                (string) $order->chat_id,
                $pdf->bytes(),
                'application/pdf',
                $pdf->nama_file,
                "Halo Kak {$order->nama_penerima} 😊\n\nBerikut kami kirim ulang invoice {$order->invoice_number} untuk pesanan *{$order->order_id}* ya kak 🙏",
                'Invoice PDF',
            );
        } catch (PengirimanGagal) {
            throw new PerintahGagal('WhatsApp menolak kiriman invoice. Coba lagi sebentar.');
        }
    }
}
