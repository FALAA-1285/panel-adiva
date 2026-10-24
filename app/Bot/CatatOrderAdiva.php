<?php

namespace App\Bot;

use App\Enums\JenisNotifikasi;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Support\KabarBot;

/**
 * The order Adiva took is written down, unless it is a double, and the supervisor (or Bu Sarah,
 * for an employee order) is told. It is n8n's Cek Order Berjalan → Putuskan Order Baru →
 * Catat Order → the two notices → Tutup Order Lama.
 */
class CatatOrderAdiva
{
    /**
     * @param  array<string, mixed>  $rb  what RapikanBalasan made
     * @return LogOrder|null null when the order was a double and nothing was written
     */
    public function catat(array $rb, ?CrmCustomer $customer, int $sekarangMs): ?LogOrder
    {
        $chat = (string) ($rb['chatId'] ?? '');
        $putusan = PutuskanOrder::putuskan($rb, LapisanData::orderChat($chat), $sekarangMs);

        if ($putusan === null) {
            return null;
        }

        $putusan = CekHargaOrder::periksa($rb, $putusan);

        $karyawan = (bool) preg_match('/POTONG GAJI/i', (string) $rb['metode_bayar']);

        LapisanData::catatOrder([
            'order_id' => $rb['order_id'],
            'tanggal' => $rb['tgl_interaksi_terakhir'],
            'chat_id' => $chat,
            'nama_penerima' => $rb['nama_penerima'],
            'nomor_wa' => $rb['nomor_wa'],
            'items' => $rb['order_items'],
            'total_produk' => $rb['order_total'],
            'alamat_lengkap' => $rb['alamat_lengkap'],
            'kecamatan' => $rb['kecamatan'],
            'kode_pos' => $rb['kode_pos'],
            'metode_bayar' => $rb['metode_bayar'],
            'tipe_customer' => $rb['tipe_customer'],
            'status_order' => $karyawan ? 'KARYAWAN' : 'MENUNGGU_KONFIRMASI',
            'no_hp' => $rb['no_hp'],
            'kelurahan' => $rb['kelurahan'],
            'kota' => $rb['kota_kirim'],
            'provinsi' => $rb['provinsi'],
            'berat_kg' => $rb['berat_kg'],
            'kurir_pilihan' => $rb['kurir_pilihan'],
            'items_detail' => json_encode($rb['order_items_detail'] ?? [], JSON_UNESCAPED_UNICODE),
            'ongkir' => $rb['order_ongkir'],
            'catatan_admin' => collect([$putusan['catatan_log'], filled($rb['rencana_ambil']) ? 'RENCANA AMBIL/BAYAR: '.$rb['rencana_ambil'] : ''])->filter()->implode(' | '),
            'sesi_wa' => $rb['session'],
        ]);

        $order = LogOrder::query()->where('order_id', $rb['order_id'])->first();

        // the packing fee is billed with the products (n8n_catat_order predates it)
        if ($order !== null && filled($rb['order_packing'] ?? null)) {
            $order->update(['biaya_packing' => (int) preg_replace('/\D/', '', (string) $rb['order_packing'])]);
        }

        $nama = filled($rb['nama_penerima']) ? $rb['nama_penerima'] : (string) $rb['nama'];
        $total = $order?->tagihan() ?? (int) preg_replace('/\D/', '', (string) $rb['order_total']) + (int) preg_replace('/\D/', '', (string) $rb['order_ongkir']);

        if ($karyawan) {
            KabarBot::notifikasi(JenisNotifikasi::OrderKaryawan, 'Order karyawan', "{$nama} · {$rb['order_id']} · diteruskan ke Bu Sarah", $customer, $order, 'SARAH_WA');
        } else {
            KabarBot::notifikasi(JenisNotifikasi::OrderBaru, 'Order baru menunggu ACC', "{$nama} · {$rb['order_id']} · ".RapikanBalasan::rp($total), $customer, $order);
        }

        // a revision of an order not ACC'd yet: the old one is closed, so it is not processed twice
        if (filled($putusan['tutup_order_lama'])) {
            LapisanData::ubahOrder([
                'order_id' => $putusan['tutup_order_lama'],
                'status_lama' => 'MENUNGGU_KONFIRMASI',
                'status_order' => 'DIBATALKAN',
                'catatan_admin' => $putusan['catatan_lama_baru'],
            ]);
        }

        // what the supervisor should know before ACC (a possible double, a revision, a wrong total)
        if (filled($putusan['catatan_notif'])) {
            KabarBot::notifikasi(
                JenisNotifikasi::OrderBaru,
                "Catatan untuk {$rb['order_id']}",
                mb_substr((string) preg_replace('/\n+/', ' · ', (string) preg_replace('/[*_~]/', '', $putusan['catatan_notif'])), 0, 600),
                $customer,
                $order,
                $karyawan ? 'SARAH_WA' : 'ADMIN_WA',
            );
        }

        return $order;
    }
}
