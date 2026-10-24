<?php

namespace App\Support;

use App\Enums\AksiPesanan;
use App\Enums\StatusOrder;
use App\Models\LogOrder;

/**
 * What the customer is told when an admin runs an order command. The words are the ones
 * n8n's "Susun Respons" always used, so a customer sees no change. Ask for the text with
 * the order as it was *before* the command: BATAL, for one, depends on the old status.
 *
 * ACC (the invoice PDF carries the news) and TUTUP (the customer is not told) say nothing here.
 */
class PesanPerintah
{
    /**
     * @param  string|null  $catatan  what the admin typed with the command (reason, waybill, who drives)
     */
    public static function untukCustomer(AksiPesanan $aksi, LogOrder $order, ?string $catatan = null): ?string
    {
        $catatan = filled($catatan) ? trim((string) $catatan) : null;
        $nama = filled($order->nama_penerima) ? $order->nama_penerima : 'Kak';
        $id = $order->order_id;
        $sendiri = PerintahPesanan::kurirSendiri($order);
        $gaji = self::potongGaji($order);
        $ambil = self::ambilSendiri($order);
        $bayarDiTempat = $ambil && ! $gaji && ! preg_match('/TRANSFER/i', $order->kurir_pilihan.' '.$order->metode_bayar);
        $lokasi = self::lokasiAmbil($order);

        $pesan = match ($aksi) {
            AksiPesanan::Tolak => "Mohon maaf Kak {$nama}, pesanan {$id} belum bisa kami proses."
                .($catatan ? "\nAlasannya: {$catatan}" : '')
                ."\n\nKalau Kakak berkenan, kami bantu carikan alternatif produk lain ya 🙏",

            AksiPesanan::Batal => "Baik Kak {$nama}, pesanan *{$id}* sudah kami batalkan sesuai permintaan Kakak ✅\n\n"
                .match ($order->status_order) {
                    StatusOrder::Karyawan => 'Potongan gajinya juga tidak jadi diproses ya kak.',
                    StatusOrder::Diacc => 'Invoice sebelumnya tidak berlaku lagi, jadi tidak ada tagihan yang perlu dibayar ya kak.',
                    default => 'Tidak ada tagihan yang perlu dibayar ya kak.',
                }
            ."\n\nKalau nanti mau pesan lagi, tinggal chat ke sini saja ya 😊",

            AksiPesanan::Siap => self::siap($order, $nama, $catatan, $gaji, $lokasi),

            AksiPesanan::Lunas => self::lunas($order, $id, $sendiri, $gaji, $ambil, $bayarDiTempat, $lokasi),

            AksiPesanan::Kirim => $sendiri !== null
                ? "Halo Kak {$nama} 😊\n\nPesanan *{$id}* sedang ".($sendiri === 'armada' ? 'dikirim dengan armada kantor Bang Bana 🚚' : 'diantar kurir Bang Bana 🛵')
                    .($catatan ? "\n".($sendiri === 'armada' ? 'Info pengiriman: ' : 'Kurir: ').$catatan : '')
                    ."\n\nMohon pastikan ada yang bisa menerima di alamat ya kak. Kalau pesanannya sudah sampai, kabari kami ya 😊"
                : ($catatan === null ? null
                    : "Pesanan {$id} sudah dikirim ✅\n\nNomor resi: *{$catatan}*\n\nMohon dipantau berkala ya. Kalau paketnya sudah sampai, kabari kami 😊"
                        .($order->ongkirCod() ? "\n\n💵 Ongkir dibayar *COD* ke kurir saat paket sampai".($order->ongkir > 0 ? ' ('.Rupiah::teks($order->ongkir).')' : '').' ya kak.' : '')),

            AksiPesanan::Selesai => "Terima kasih banyak Kak {$nama} 🙏\n\nSemoga camilannya cocok di lidah Kakak."
                .(filled($ulasan = PengaturanToko::nilai('LINK_ULASAN')) ? " Kalau berkenan, boleh tinggalkan ulasan singkat untuk kami di {$ulasan}." : '')
                ."\n\nKalau nanti mau pesan lagi, tinggal chat ke sini saja ya 😊",

            AksiPesanan::Acc, AksiPesanan::Tutup => null,
        };

        return $pesan === null ? null : self::sebutInvoice($pesan, $order);
    }

    /**
     * Pickup orders: the shop or the factory in Lawang. The place is whatever follows
     * "AMBIL SENDIRI - " in the courier column.
     */
    public static function lokasiAmbil(LogOrder $order): string
    {
        $lokasi = trim((string) preg_replace('/^\s*AMBIL SENDIRI\s*-\s*/i', '', (string) $order->kurir_pilihan));

        return $lokasi !== '' ? $lokasi : 'toko kami';
    }

    /**
     * Picked up by the customer: self pickup, pay at the shop, or an employee order.
     */
    public static function ambilSendiri(LogOrder $order): bool
    {
        return (bool) preg_match('/AMBIL SENDIRI|PICKUP|BAYAR DI TEMPAT/i', $order->kurir_pilihan.' '.$order->metode_bayar);
    }

    public static function potongGaji(LogOrder $order): bool
    {
        return (bool) preg_match('/POTONG GAJI/i', (string) $order->metode_bayar);
    }

    /**
     * Price of the products: the items, or the order's total when it has none.
     */
    public static function totalProduk(LogOrder $order): int
    {
        $dariItem = (int) $order->items->sum(fn ($i) => $i->qty * $i->harga_satuan);

        return $dariItem > 0 ? $dariItem : (int) $order->total_produk;
    }

    private static function siap(LogOrder $order, string $nama, ?string $catatan, bool $gaji, string $lokasi): string
    {
        $lawang = (bool) preg_match('/lawang|pabrik/i', $lokasi);
        $info = $gaji ? '' : trim((string) PengaturanToko::nilai($lawang ? 'AMBIL_PABRIK_LAWANG' : 'AMBIL_TOKO_SAWOJAJAR'));
        $tagihan = self::totalProduk($order) + (int) $order->ongkir;
        $lunas = $order->status_order === StatusOrder::Lunas;

        return "Halo Kak {$nama} 😊\n\nPesanan *{$order->order_id}* sudah *siap diambil* ✅\n\n"
            .($gaji
                ? "📍 Pengambilan sesuai info di grup Anak Bang Bana ya kak.\nPembayarannya tetap lewat potong gaji."
                : "📍 *{$lokasi}*".($info !== '' ? "\n{$info}" : '')."\n\n"
                    .($lunas ? '💳 Pembayarannya sudah lunas, tinggal diambil saja ya kak.' : '💰 Pembayaran di tempat saat pengambilan: *'.($tagihan > 0 ? Rupiah::teks($tagihan) : '-').'*'))
            .($catatan ? "\n\n📝 Catatan dari tim kami: {$catatan}" : '')
            ."\n\nSaat mengambil, cukup sebutkan nomor pesanan *{$order->order_id}* ke tim kami ya kak 🙏";
    }

    private static function lunas(LogOrder $order, string $id, ?string $sendiri, bool $gaji, bool $ambil, bool $bayarDiTempat, string $lokasi): string
    {
        $dibukaLagi = $order->status_order === StatusOrder::BatalOtomatis;

        $pesan = match (true) {
            $gaji => "Pesanan {$id} sudah dicatat sebagai potongan gaji ya kak ✅\n\nTerima kasih ya kak, kalau mau pesan lagi tinggal chat ke sini 😊",
            $bayarDiTempat => "Pembayaran untuk {$id} sudah kami terima ✅\n\nTerima kasih sudah mampir dan mengambil pesanannya ya Kak 🙏\n\nKalau nanti mau pesan lagi, tinggal chat ke sini saja ya 😊",
            $ambil => "Pembayaran untuk {$id} sudah kami terima ✅\n\nPesanan Kakak kami siapkan dulu ya. Nanti kami kabari kalau sudah siap diambil di {$lokasi} ya 😊",
            $sendiri === 'armada' => "Pembayaran untuk {$id} sudah kami terima ✅\n\nPesanan Kakak kami siapkan dulu, nanti dikirim dengan armada kantor Bang Bana 🚚. Kami kabari lagi di chat ini saat armadanya berangkat ya 😊",
            $sendiri === 'motor' => "Pembayaran untuk {$id} sudah kami terima ✅\n\nPesanan Kakak kami siapkan dulu, nanti diantar langsung oleh kurir Bang Bana 🛵. Kami kabari lagi di chat ini saat kurirnya berangkat ya 😊",
            default => "Pembayaran untuk {$id} sudah kami terima ✅\n\nPesanan Kakak masuk antrian pengemasan. Nomor resinya nanti kami kirim ke chat ini ya 😊",
        };

        return $dibukaLagi ? $pesan."\n\n(Pesanan yang sempat tertutup karena lewat batas bayar sudah kami aktifkan lagi ya kak 🙏)" : $pesan;
    }

    /**
     * "ORD-… / INV/…": the invoice number goes beside the order number once there is one.
     */
    private static function sebutInvoice(string $pesan, LogOrder $order): string
    {
        $invoice = trim((string) $order->invoice_number);

        if ($invoice === '' || str_contains($pesan, $invoice)) {
            return $pesan;
        }

        return preg_replace('/'.preg_quote($order->order_id, '/').'/', "{$order->order_id} / {$invoice}", $pesan, 1) ?? $pesan;
    }
}
