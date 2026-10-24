<?php

namespace App\Support;

use App\Enums\StatusOrder;
use App\Models\LogOrder;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The invoice a customer gets when an order is ACC'd: the PDF, the words sent with it,
 * and the plain-text version used when WhatsApp refuses the file. It replaces n8n's
 * "Buat Invoice PDF" and "Susun Invoice Teks".
 */
class InvoiceAdiva
{
    /** Rows on one page; a longer order continues on the next ("Bersambung …"). */
    public const BARIS_PER_HALAMAN = 16;

    /**
     * @return array<string, mixed> what resources/views/invoice/adiva.blade.php prints
     */
    public static function data(LogOrder $order, ?User $oleh = null): array
    {
        $order->loadMissing('items');
        $ambil = PesanPerintah::ambilSendiri($order);
        $gaji = PesanPerintah::potongGaji($order);
        $bayarDiTempat = $ambil && ! $gaji && ! preg_match('/TRANSFER/i', (string) $order->metode_bayar);
        $lokasi = PesanPerintah::lokasiAmbil($order);
        $lunas = in_array($order->status_order, [StatusOrder::Lunas, StatusOrder::Dikirim, StatusOrder::Selesai], true);
        $tanggal = now()->timezone('Asia/Jakarta')->format('d/m/Y');

        $item = $order->items->map(fn ($i) => [
            'nama' => $i->nama_produk, 'qty' => $i->qty, 'harga' => $i->harga_satuan, 'unit' => 'Pcs',
        ])->all();

        // an order without lines is one line for its whole price
        if ($item === []) {
            $item = [['nama' => '-', 'qty' => 1, 'harga' => (int) $order->total_produk, 'unit' => 'Pcs']];
        }

        $subtotal = (int) array_sum(array_map(fn ($i) => $i['qty'] * $i['harga'], $item));
        $cod = $order->ongkirCod();
        // an ONGKIR COD cost is paid to the courier: shown in the notes, not billed
        $ongkir = $cod ? 0 : (int) $order->ongkir;
        $packing = (int) $order->biaya_packing;
        $total = $subtotal + $ongkir + $packing;
        $kirim = 'KIRIM - '.($order->kurir_pilihan ?: '-')
            .($cod ? ($order->ongkir > 0 ? ' - ongkir '.Rupiah::teks($order->ongkir).' bayar ke kurir' : ' - ongkir bayar ke kurir') : '');

        return [
            'customer' => self::rapi($order->nama_penerima ?: '-'),
            'telp' => self::rapi($order->no_hp ?: ($order->nomor_wa ?: '-')),
            'alamat' => self::rapi($ambil
                ? ($gaji ? 'Pengambilan sesuai info grup Anak Bang Bana' : "Ambil di {$lokasi}")
                : (collect([$order->alamat_lengkap, $order->kelurahan, $order->kecamatan, $order->kota, $order->kode_pos])->filter()->implode(', ') ?: '-')),
            'invoice_number' => $order->invoice_number ?: '-',
            'tgl_invoice' => $tanggal,
            'estimated_shipping' => $tanggal,
            'platform' => 'WHATSAPP/'.($gaji ? 'POTONG GAJI' : ($bayarDiTempat ? 'CASH' : 'TF')),
            // an invoice from the chatbot is always in Adiva's name, whoever pressed ACC
            'admin' => 'ADIVA',
            'halaman' => array_chunk(array_map(fn ($i) => [...$i, 'nama' => self::rapi($i['nama'])], $item), self::BARIS_PER_HALAMAN),
            'subtotal' => $subtotal,
            'shipping_cost' => $ongkir,
            'packing_cost' => $packing,
            'diskon_produk' => 0,
            'diskon_ongkir' => 0,
            'total_amount' => $total,
            'grand_total' => $total,
            'terbilang' => Terbilang::rupiah($total),
            'notes1' => $gaji ? 'AMBIL KANTOR' : ($ambil ? 'AMBIL '.mb_strtoupper($lokasi) : $kirim),
            'notes2' => $lunas ? 'LUNAS' : ($gaji ? 'POTONG GAJI' : 'BELUM LUNAS'),
            'catatan_bayar' => self::catatanBayar($gaji, $bayarDiTempat, $lokasi),
            'catatan_tukar' => 'Barang yang sudah dibeli tidak dapat ditukar atau dikembalikan',
        ];
    }

    /**
     * The PDF's bytes.
     */
    public static function pdf(LogOrder $order, ?User $oleh = null): string
    {
        $opsi = new Options;
        $opsi->set('isRemoteEnabled', false);
        $opsi->set('defaultFont', 'Helvetica');

        $pdf = new Dompdf($opsi);
        $pdf->setPaper('A4');
        $pdf->loadHtml(view('invoice.adiva', self::data($order, $oleh))->render());
        $pdf->render();

        return (string) $pdf->output();
    }

    /**
     * "INV-2026-09-142.pdf"
     */
    public static function namaFile(LogOrder $order): string
    {
        return (preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $order->invoice_number) ?: 'invoice').'.pdf';
    }

    /**
     * The words sent with the PDF.
     */
    public static function keterangan(LogOrder $order, bool $revisi): string
    {
        $nama = (string) $order->nama_penerima;
        $nomor = (string) $order->invoice_number;
        $ambil = PesanPerintah::ambilSendiri($order);
        $bayarDiTempat = $ambil && ! PesanPerintah::potongGaji($order) && ! preg_match('/TRANSFER/i', (string) $order->metode_bayar);
        $lokasi = PesanPerintah::lokasiAmbil($order);

        $buka = $revisi
            ? "Ada *pembaruan ongkir* untuk pesanan *{$order->order_id}* ya kak 🙏\nBerikut *invoice revisi*-nya ({$nomor}). Mohon transfer sesuai *total di invoice ini* — invoice sebelumnya tidak berlaku lagi.\n\n"
            : "Pesanan *{$order->order_id}* sudah kami setujui ✅\nBerikut *invoice* pesanannya ya kak ({$nomor}).\n\n";

        $bayar = match (true) {
            $bayarDiTempat => "Invoice ini sebagai *bukti pesanan* ya kak 🙏\nPembayaran dilakukan langsung *di tempat* saat pengambilan di {$lokasi}. Cukup tunjukkan invoice ini ke tim kami saat mengambil pesanan ya kak.",
            $ambil => "Pembayaran bisa ditransfer ke *rekening yang tertera di invoice*.\nSetelah transfer, mohon kirim foto bukti transfernya ke chat ini ya kak. Pesanan kami siapkan setelah pembayaran dikonfirmasi supervisor kami, nanti kami kabari kalau sudah siap diambil di {$lokasi} 🙏",
            default => "Pembayaran bisa ditransfer ke *rekening yang tertera di invoice*.\nSetelah transfer, mohon kirim foto bukti transfernya ke chat ini ya kak. Pesanan kami proses setelah pembayaran dikonfirmasi supervisor kami 🙏",
        };

        return "Halo Kak {$nama} 😊\n\n".$buka.$bayar.self::batasBayar($ambil, $bayarDiTempat);
    }

    /**
     * The invoice as a chat message, for when the PDF could not be delivered.
     */
    public static function teks(LogOrder $order, bool $revisi): string
    {
        $order->loadMissing('items');
        $ambil = PesanPerintah::ambilSendiri($order);
        $bayarDiTempat = $ambil && ! PesanPerintah::potongGaji($order) && ! preg_match('/TRANSFER/i', (string) $order->metode_bayar);
        $lokasi = PesanPerintah::lokasiAmbil($order);
        $kurir = (string) $order->kurir_pilihan;
        $rp = fn (int $n) => Rupiah::teks($n);

        $baris = $order->items->isEmpty()
            ? ['• - (1 x '.$rp((int) $order->total_produk).') — '.$rp((int) $order->total_produk)]
            : $order->items->map(fn ($i) => "• {$i->nama_produk} ({$i->qty} x ".$rp($i->harga_satuan).') — '.$rp($i->qty * $i->harga_satuan))->all();
        $subtotal = PesanPerintah::totalProduk($order);
        $cod = $order->ongkirCod();
        $ongkir = (int) $order->ongkir;
        $packing = (int) $order->biaya_packing;

        if ($bayarDiTempat) {
            $bayar = "Pembayaran dilakukan langsung *di tempat* saat pengambilan di {$lokasi}. Cukup tunjukkan pesan ini ke tim kami ya kak 🙏";
        } else {
            $bayar = "💳 *Pembayaran transfer ke:*\n".(PengaturanToko::nilai('REKENING') ?: '-')."\n\n"
                .'Setelah transfer, mohon kirim foto bukti transfernya ke chat ini ya kak. '
                .($ambil
                    ? "Pesanan kami siapkan setelah pembayaran dikonfirmasi supervisor kami, nanti kami kabari kalau sudah siap diambil di {$lokasi} 🙏"
                    : 'Pesanan kami proses setelah pembayaran dikonfirmasi supervisor kami 🙏')
                .self::batasBayar($ambil, false);
        }

        return "Halo Kak {$order->nama_penerima} 😊\n\n"
            .($revisi
                ? "Ada *pembaruan ongkir* untuk pesanan *{$order->order_id}* ya kak 🙏\nBerikut *invoice revisi*-nya (invoice sebelumnya tidak berlaku lagi):\n\n"
                : "Pesanan *{$order->order_id}* sudah kami setujui ✅\nBerikut invoice pesanannya ya kak:\n\n")
            ."🧾 *INVOICE {$order->invoice_number}*\n"
            .'Tanggal: '.now()->timezone('Asia/Jakarta')->format('d/m/Y')."\n"
            .($ambil ? "Pengambilan: {$lokasi}\n" : ($kurir !== '' ? "Kurir: {$kurir}\n" : ''))
            ."\n📦 *Rincian pesanan*\n".implode("\n", $baris)."\n\n"
            .'💰 Total produk: '.$rp($subtotal)."\n"
            .($cod
                ? '🚚 Ongkir ekspedisi: '.($ongkir > 0 ? $rp($ongkir).' ' : '')."*dibayar COD ke kurir saat paket sampai* (tidak ikut transfer)\n"
                : ($ongkir > 0 ? '🚚 Ongkir: '.$rp($ongkir)."\n" : ''))
            .($packing > 0 ? '📦 Packing: '.$rp($packing)."\n" : '')
            .'*Total bayar: '.$rp($subtotal + $packing + ($cod ? 0 : $ongkir))."*\n\n"
            .$bayar;
    }

    /**
     * "jam kerja 07.00–21.00, Minggu tidak dihitung", from JAM_PESAN_OTOMATIS and HARI_LIBUR_OTOMATIS.
     */
    public static function teksJamKerja(): string
    {
        $jam = (string) (PengaturanToko::nilai('JAM_PESAN_OTOMATIS') ?: '07:00-21:00');
        $rentang = preg_match('/(\d{1,2})[.:](\d{2})\s*[-–]\s*(\d{1,2})[.:](\d{2})/u', $jam, $m)
            ? sprintf('%02d.%s–%02d.%s', $m[1], $m[2], $m[3], $m[4])
            : '07.00–21.00';

        $libur = PengaturanToko::nilai('HARI_LIBUR_OTOMATIS');
        $hari = collect(preg_split('/[,;\n]+/', $libur === null ? 'Minggu' : (string) $libur) ?: [])
            ->map(fn ($h) => trim($h))
            ->filter(fn ($h) => $h !== '' && ! preg_match('/\d{4}-\d{2}-\d{2}/', $h))
            ->all();

        return "jam kerja {$rentang}".($hari !== [] ? ', '.implode('/', $hari).' tidak dihitung' : '');
    }

    private static function batasBayar(bool $ambil, bool $bayarDiTempat): string
    {
        if ($bayarDiTempat) {
            return '';
        }

        $jam = $ambil
            ? (PengaturanToko::nilai('BATAS_BAYAR_PICKUP_JAM') ?: PengaturanToko::nilai('BATAS_BAYAR_JAM'))
            : PengaturanToko::nilai('BATAS_BAYAR_JAM');

        return filled($jam) ? "\n\nPesanan kami tahan {$jam} jam kerja (".self::teksJamKerja().').' : '';
    }

    private static function catatanBayar(bool $gaji, bool $bayarDiTempat, string $lokasi): string
    {
        return match (true) {
            $gaji => 'Pembayaran dipotong dari gaji karyawan sesuai ketentuan program karyawan Bana Food',
            $bayarDiTempat => "Pembayaran dilakukan langsung di tempat saat pengambilan di {$lokasi}",
            default => 'Pembayaran dapat dilakukan secara tunai atau transfer via '.(PengaturanToko::nilai('REKENING') ?: '-'),
        };
    }

    /**
     * The PDF's built-in font has no emoji or symbols: drop what it cannot draw, and write
     * the dashes and quotes WhatsApp users paste in as plain ones.
     */
    private static function rapi(string $teks): string
    {
        $teks = preg_replace('/[\x{10000}-\x{10FFFF}\x{FE0F}\x{200D}]/u', '', $teks) ?? $teks;

        return trim(strtr($teks, ["\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{00A0}" => ' ']));
    }
}
