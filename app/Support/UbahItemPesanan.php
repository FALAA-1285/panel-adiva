<?php

namespace App\Support;

use App\Enums\KategoriAktivitas;
use App\Enums\StatusOrder;
use App\Enums\TipeCustomer;
use App\Models\LogOrder;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Detail pesanan, "Ubah item": items can change until the invoice is made (ACC). A
 * manual line keeps no product link, like an order typed in on Catat order manual.
 */
class UbahItemPesanan
{
    /**
     * Why "Ubah item" cannot be used now, or null when it can.
     */
    public static function alasanTidakBisa(LogOrder $order, User $oleh): ?string
    {
        if (! in_array($order->status_order, [StatusOrder::MenungguKonfirmasi, StatusOrder::Karyawan], true)) {
            return 'Item hanya bisa diubah sebelum ACC; invoice sudah dibuat.';
        }

        $boleh = HakAkses::boleh($oleh, 'catat')
            || ($order->tipe_customer === TipeCustomer::Karyawan && HakAkses::boleh($oleh, 'order_karyawan'));

        return $boleh ? null : 'Peran Anda tidak bisa mengubah item order.';
    }

    /**
     * Replace the items and the product total; shipping stays as it was.
     *
     * @param  list<array{nama: string, qty: int, harga: int}>  $item
     */
    public static function simpan(LogOrder $order, array $item, User $oleh): void
    {
        DB::transaction(function () use ($order, $item, $oleh) {
            $sebelum = $order->items->map(fn (OrderItem $i) => "{$i->nama_produk} ×{$i->qty}")->implode(', ');

            $order->items()->delete();

            foreach ($item as $urutan => $baris) {
                $order->items()->create([
                    'urutan' => $urutan + 1,
                    'nama_produk' => $baris['nama'],
                    'qty' => $baris['qty'],
                    'harga_satuan' => $baris['harga'],
                    'subtotal' => $baris['qty'] * $baris['harga'],
                ]);
            }

            $total = (int) collect($item)->sum(fn (array $b) => $b['qty'] * $b['harga']);
            $order->update(['total_produk' => $total]);
            $sesudah = collect($item)->map(fn (array $b) => "{$b['nama']} ×{$b['qty']}")->implode(', ');

            $order->riwayat()->create([
                'waktu' => now(),
                'jenis' => 'UBAH_ITEM',
                'nada' => 'blue',
                'teks' => "Item diubah oleh {$oleh->name} dari panel",
                'data' => ['lengkap' => "Item diubah oleh {$oleh->name}: {$sebelum} → {$sesudah} · total produk ".Rupiah::teks($total)],
            ]);

            Aktivitas::catat($oleh, KategoriAktivitas::Pesanan, 'Ubah item order', "{$order->order_id} · total produk ".Rupiah::teks($total), $order);
        });
    }
}
