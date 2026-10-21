<?php

namespace App\Support;

use App\Enums\StatusOrder;
use App\Enums\TipeCustomer;
use App\Models\LogOrder;
use Carbon\CarbonInterface;

/**
 * The steps an order goes through (Orders.dc.html: the bars under the order number,
 * OrderDetail.dc.html: the step card): sent by courier, picked up, or an employee order.
 */
class AlurPesanan
{
    /**
     * A cancelled or rejected order has no current step: every bar is red.
     *
     * @param  bool  $lengkap  OrderDetail writes "Invoice terkirim", Pesanan "Invoice"
     * @return list<array{label: string, selesai: bool, kini: bool, mati: bool, waktu: CarbonInterface|null}>
     */
    public static function langkah(LogOrder $o, bool $lengkap = false): array
    {
        $alur = $o->tipe_customer === TipeCustomer::Karyawan
            ? [['Karyawan', $o->tanggal], ['Lunas (gaji)', $o->lunas_at], ['Selesai', $o->selesai_at]]
            : [
                ['Menunggu ACC', $o->tanggal],
                [$lengkap ? 'Invoice terkirim' : 'Invoice', $o->diacc_at],
                ['Lunas', $o->lunas_at],
                $o->ambil_sendiri ? ['Diambil', $o->siap_diambil_at] : ['Dikirim', $o->dikirim_at],
                ['Selesai', $o->selesai_at],
            ];

        $kini = count($alur) === 3
            ? match ($o->status_order) {
                StatusOrder::Karyawan, StatusOrder::MenungguKonfirmasi => 0,
                StatusOrder::Lunas, StatusOrder::Diacc => 1,
                StatusOrder::Selesai => 2,
                default => null,
            }
        : match ($o->status_order) {
            StatusOrder::MenungguKonfirmasi, StatusOrder::Karyawan => 0,
            StatusOrder::Diacc => 1,
            StatusOrder::Lunas => 2,
            StatusOrder::Dikirim => 3,
            StatusOrder::Selesai => 4,
            default => null,
        };

        return array_map(fn (array $langkah, int $i) => [
            'label' => $langkah[0],
            'selesai' => $kini !== null && $i <= $kini,
            'kini' => $i === $kini,
            'mati' => $kini === null,
            'waktu' => $langkah[1],
        ], $alur, array_keys($alur));
    }
}
