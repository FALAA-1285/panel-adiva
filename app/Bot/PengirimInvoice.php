<?php

namespace App\Bot;

use App\Enums\JenisNotifikasi;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Models\User;
use App\Support\InvoiceAdiva;
use App\Support\KabarBot;
use App\Support\PesanKeluar;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Makes the invoice of an ACC'd order, keeps the PDF for "Unduh PDF" and "Kirim ulang", and
 * sends it to the customer. When the file will not go it tries the customer's other chat
 * id, and then sends the invoice as plain text; the supervisor is told either way.
 * This is n8n's chain Buat Invoice PDF → Kirim Invoice PDF → (Cadangan) → Kirim Invoice Teks.
 */
class PengirimInvoice
{
    public function __construct(private PesanKeluar $pesan) {}

    public function kirim(LogOrder $order, ?User $oleh, bool $revisi): void
    {
        $sesi = PesanKeluar::sesi($order->sesi_wa);
        $chatAsli = (string) $order->chat_id;
        $chatNomor = $this->jid($order->nomor_wa);

        // an @lid id is not a number: the phone number is the first try, the chat id the second
        if ($chatNomor !== '' && preg_replace('/\D/', '', explode('@', $chatAsli)[0]) === explode('@', $chatNomor)[0]) {
            $chatNomor = '';
        }

        $utama = $chatNomor !== '' ? $chatNomor : $chatAsli;
        $cadangan = $chatAsli !== '' && $chatAsli !== $utama ? $chatAsli : '';
        $pdf = null;

        try {
            $pdf = InvoiceAdiva::pdf($order, $oleh);
            $this->simpan($order, InvoiceAdiva::namaFile($order), $pdf);
        } catch (Throwable $e) {
            report($e);
            $pdf = null;
        }

        if ($pdf !== null) {
            foreach (array_filter([$utama, $cadangan]) as $chat) {
                try {
                    $this->pesan->berkas($sesi, $chat, $pdf, 'application/pdf', InvoiceAdiva::namaFile($order), InvoiceAdiva::keterangan($order, $revisi), 'Invoice PDF');

                    return;
                } catch (PengirimanGagal) {
                    // the next chat id, then the text
                }
            }
        }

        $customer = CrmCustomer::query()->where('chat_id', $chatAsli)->first();
        $rincian = "{$order->nama_penerima} · {$order->order_id}";

        try {
            $this->pesan->teks($sesi, $cadangan !== '' ? $cadangan : $utama, InvoiceAdiva::teks($order, $revisi));
            KabarBot::notifikasi(JenisNotifikasi::InvoiceGagal, 'Invoice PDF gagal, dikirim versi teks', "{$rincian} · dikirim versi teks", $customer, $order);
        } catch (PengirimanGagal) {
            KabarBot::notifikasi(JenisNotifikasi::InvoiceGagal, 'Invoice PDF gagal dibuat', "{$rincian} · PDF gagal dibuat", $customer, $order);
        }
    }

    /**
     * The PDF kept for the order (one per order; a revised invoice replaces it).
     */
    private function simpan(LogOrder $order, string $namaFile, string $pdf): void
    {
        // bytea through base64: raw bytes would not survive the query parameter
        DB::statement(
            "INSERT INTO invoice_pdf (log_order_id, nama_file, isi, created_at, updated_at) VALUES (?, ?, decode(?, 'base64'), now(), now())
             ON CONFLICT (log_order_id) DO UPDATE SET nama_file = excluded.nama_file, isi = excluded.isi, updated_at = now()",
            [$order->id, mb_substr($namaFile, 0, 120), base64_encode($pdf)],
        );
    }

    /**
     * "6281234567890@c.us" from a number written 0812…, 812… or 62812…; '' unless it is an Indonesian mobile number.
     */
    private function jid(?string $nomor): string
    {
        $d = (string) preg_replace('/\D/', '', (string) $nomor);

        if ($d === '') {
            return '';
        }

        $d = match (true) {
            str_starts_with($d, '0') => '62'.substr($d, 1),
            str_starts_with($d, '8') => '62'.$d,
            default => $d,
        };

        return preg_match('/^628\d{7,11}$/', $d) ? "{$d}@c.us" : '';
    }
}
