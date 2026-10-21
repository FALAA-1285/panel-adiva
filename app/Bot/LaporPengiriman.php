<?php

namespace App\Bot;

use App\Enums\JenisNotifikasi;
use App\Enums\StatusOrder;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Models\Riwayat;
use App\Support\KabarBot;
use App\Support\PesanKeluar;

/**
 * What follows booking or cancelling a shipment: the order takes the new status, waybill and
 * note, the customer is told (a waybill), and the supervisor gets a notice with the whole
 * story. n8n's Catat Pengiriman → Kirim Resi ke Customer → Notifikasi: Pengiriman.
 */
class LaporPengiriman
{
    public function __construct(private PesanKeluar $pesan) {}

    public function terapkan(LogOrder $order, HasilPengiriman $hasil): void
    {
        if ($hasil->simpanCatatan) {
            $status = StatusOrder::tryFrom($hasil->statusBaru) ?? $order->status_order;
            $berubah = $status !== $order->status_order;

            $order->update([
                'status_order' => $status,
                'catatan_admin' => $hasil->catatanAdmin,
                'no_resi' => $hasil->resi !== '' ? $hasil->resi : null,
                'kurir_final' => $hasil->kurirFinal !== '' ? $hasil->kurirFinal : null,
                ...($berubah && $status === StatusOrder::Dikirim ? ['dikirim_at' => now()] : []),
            ]);

            if ($hasil->ok && $berubah && $status === StatusOrder::Dikirim) {
                Riwayat::create([
                    'subjek_type' => $order->getMorphClass(),
                    'subjek_id' => $order->id,
                    'waktu' => now(),
                    'jenis' => 'KIRIM',
                    'nada' => 'blue',
                    'teks' => "KIRIM otomatis lewat Mengantar · {$hasil->kurirFinal} · resi {$hasil->resi}",
                ]);
            }
        }

        $gagalKirim = false;

        if (filled($hasil->pesanCustomer) && filled($order->chat_id)) {
            try {
                $this->pesan->teks(PesanKeluar::sesi($order->sesi_wa), (string) $order->chat_id, (string) $hasil->pesanCustomer);
            } catch (PengirimanGagal) {
                $gagalKirim = true;
            }
        }

        $detail = (string) preg_replace('/[*_~]/', '', $hasil->pesanAdmin !== '' ? $hasil->pesanAdmin : "Order pengiriman Mengantar untuk {$order->order_id} gagal diproses. Buat manual di aplikasi Mengantar, lalu isi resinya di panel (KIRIM).");

        KabarBot::notifikasi(
            JenisNotifikasi::Pengiriman,
            "Pengiriman {$order->order_id}",
            mb_substr($detail.($gagalKirim ? ' Pesan resi ke customer GAGAL terkirim, kabari customer secara manual.' : ''), 0, 600),
            CrmCustomer::query()->where('chat_id', $order->chat_id)->first(),
            $order,
        );
    }
}
