<?php

namespace App\Bot;

use App\Enums\AksiPesanan;
use App\Enums\KategoriAktivitas;
use App\Enums\SumberAktivitas;
use App\Models\ActivityLog;
use App\Models\LogOrder;
use App\Models\User;
use App\Support\Aktivitas;
use App\Support\PerintahPesanan;
use App\Support\Rupiah;

/**
 * Hands the command to n8n through a signed webhook. n8n runs it the way it runs an
 * admin's WhatsApp command (changes log_order, writes the history, makes the invoice,
 * tells the customer) and answers once the database has changed.
 */
class N8nPerintahOrder implements PerintahOrder
{
    public function __construct(private N8nWebhook $n8n) {}

    public function jalankan(AksiPesanan $aksi, LogOrder $order, User $oleh, ?string $catatan = null): void
    {
        $catatan = filled($catatan) ? trim((string) $catatan) : null;
        $mulai = now()->subSecond();

        $this->n8n->kirim($aksi->value, [
            'order_id' => $order->order_id,
            'catatan' => $catatan,
            'oleh' => ['id' => $oleh->id, 'nama' => $oleh->name, 'nomor_wa' => $oleh->nomor_wa],
        ]);

        // n8n changed the order; log what the panel user did, with the new values. The status
        // trigger saw n8n's write as "Sistem": this user's line below replaces it.
        $order->refresh();
        ActivityLog::query()
            ->where('subjek_type', $order->getMorphClass())->where('subjek_id', $order->id)
            ->where('sumber', SumberAktivitas::Sistem)->where('aksi', 'Status order berubah')
            ->where('waktu', '>=', $mulai)
            ->delete();
        Aktivitas::catat($oleh, KategoriAktivitas::Pesanan, "{$aksi->value} order", collect([
            $order->order_id,
            match ($aksi) {
                AksiPesanan::Acc => collect([
                    $order->invoice_number ? "invoice {$order->invoice_number} terkirim" : null,
                    is_int($ongkir = PerintahPesanan::ongkir($catatan)) ? 'ongkir '.($ongkir === 0 ? 'gratis' : Rupiah::teks($ongkir)) : null,
                ])->filter()->implode(' · ') ?: null,
                AksiPesanan::Kirim => $order->no_resi ? "resi {$order->no_resi}" : null,
                AksiPesanan::Lunas => Rupiah::teks($order->tagihan()),
                default => $aksi->keterangan(),
            },
            in_array($aksi, [AksiPesanan::Batal, AksiPesanan::Tolak, AksiPesanan::Tutup], true) ? $catatan : null,
        ])->filter()->implode(' · '), $order);
    }
}
