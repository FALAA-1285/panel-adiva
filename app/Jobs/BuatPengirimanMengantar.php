<?php

namespace App\Jobs;

use App\Bot\LaporPengiriman;
use App\Bot\PengirimanMengantar;
use App\Enums\JenisNotifikasi;
use App\Enums\StatusOrder;
use App\Models\LogOrder;
use App\Support\KabarBot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Books the order's shipment at Mengantar after LUNAS, or when the admin presses KIRIM
 * without a waybill. It takes several calls to Mengantar (pickup address, destination,
 * schedule, order), so it runs in the queue; the result reaches the supervisor as a notice.
 */
class BuatPengirimanMengantar implements ShouldQueue
{
    use Queueable;

    /** A second try could book the shipment twice. */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $orderId) {}

    public function handle(PengirimanMengantar $mengantar, LaporPengiriman $lapor): void
    {
        $order = LogOrder::query()->with('items')->find($this->orderId);

        // the order moved on (cancelled, already sent) while the job waited
        if ($order === null || $order->status_order !== StatusOrder::Lunas) {
            return;
        }

        $lapor->terapkan($order, $mengantar->buat($order));
    }

    public function failed(?Throwable $galat): void
    {
        $order = LogOrder::query()->find($this->orderId);

        if ($order !== null) {
            KabarBot::notifikasi(
                JenisNotifikasi::Pengiriman,
                "Pengiriman {$order->order_id}",
                "Order pengiriman Mengantar untuk {$order->order_id} gagal diproses. Buat manual di aplikasi Mengantar, lalu isi resinya di panel (KIRIM).",
                order: $order,
            );
        }
    }
}
