<?php

namespace App\Bot;

use App\Enums\StatusOrder;
use App\Models\LogOrder;
use App\Support\Rupiah;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What to do with a photo, a file or a voice note from a customer: which order it is the
 * transfer proof of, what to tell the supervisor, and what to answer the customer. It is
 * n8n's "Susun Pesan Lampiran".
 *
 * The order it belongs to is the customer's latest one waiting for payment (DIACC), else
 * the latest waiting for ACC, else one the payment deadline closed within the last 3 days
 * (the money may well have come in).
 */
final readonly class LampiranMasuk
{
    /** A proof arriving this long after the order was closed automatically still counts. */
    private const JAM_BUKTI_TERLAMBAT = 72;

    public function __construct(
        public ?LogOrder $order,
        public string $pesanAdmin,
        public string $pesanCustomer,
        public bool $tandaiBukti,
        public string $catatanBukti,
    ) {}

    /**
     * @param  Collection<int, LogOrder>  $orders  the customer's orders, oldest first
     */
    public static function susun(PesanMasuk $pesan, Collection $orders, string $labelNomorBot = ''): self
    {
        $nomor = explode('@', $pesan->dari())[0];
        $nama = $pesan->namaWa();
        $caption = trim($pesan->body());
        $media = $pesan->media();
        $mimetype = (string) ($media['mimetype'] ?? '');
        $bisaDibuka = ($pesan->payload['hasMedia'] ?? false) && filled($media['url'] ?? null);
        $adaGambar = $bisaDibuka && str_starts_with($mimetype, 'image');
        $adaFile = $bisaDibuka && ! $adaGambar;
        $suara = in_array($pesan->tipe(), ['ptt', 'audio'], true) || str_starts_with($mimetype, 'audio');

        $order = self::pilihOrder($orders);
        $sudahBatalOtomatis = $order !== null && $order->status_order !== StatusOrder::Diacc && $order->status_order !== StatusOrder::MenungguKonfirmasi;
        $catatanLama = (string) $order?->catatan_admin;
        $stamp = now()->timezone('Asia/Jakarta')->format('Y-m-d H:i');

        // the order is marked once, so Batas Bayar does not cancel or chase an order whose proof is in
        $tandai = $order !== null && ! $sudahBatalOtomatis && ! $suara && ($adaGambar || $adaFile) && ! str_contains($catatanLama, 'BUKTI MASUK');
        $catatanBukti = $tandai ? ($catatanLama !== '' ? $catatanLama.' | ' : '').'BUKTI MASUK '.$stamp : '';

        $nomorOrder = (string) $order?->nomor_wa;
        $kontak = preg_match('/^628\d{7,11}$/', $nomorOrder) ? "wa.me/{$nomorOrder}"
            : (preg_match('/^628\d{7,11}$/', $nomor) ? "wa.me/{$nomor}" : $pesan->dari());
        $dari = ($nama !== '' ? $nama : $nomor)." ({$kontak})";
        $depan = $nama !== '' ? explode(' ', $nama)[0] : '';

        if ($order !== null && ! $suara) {
            $admin = self::untukOrder($order, $dari, $caption, $adaGambar || $adaFile, $sudahBatalOtomatis, $tandai || str_contains($catatanLama, 'BUKTI MASUK'));
            $customer = "Bukti pembayarannya sudah kami terima ya Kak {$depan}, sedang kami cek dulu ya. Nanti kami kabari lagi di sini begitu pembayarannya dikonfirmasi supervisor kami.";
        } elseif ($suara) {
            $admin = "🎤 *Voice note dari customer*\n\nDari: {$dari}".($order !== null ? "\nOrder berjalan: *{$order->order_id}* ({$order->status_order->value})" : '')
                ."\n\nBot tidak bisa mendengarkan pesan suara. Customer sudah diminta mengetik pesannya, tapi mohon didengarkan juga ya.";
            $customer = "Maaf kak, pesan suaranya belum bisa kami dengarkan lewat chat ini 🙏\nBoleh diketik saja pesannya ya kak, supaya bisa langsung kami bantu 😊";
        } else {
            $admin = "📎 *Lampiran masuk dari customer*\n\nDari: {$dari}".($caption !== '' ? "\nCatatan customer: {$caption}" : '')
                ."\n\nTidak ada order yang menunggu pembayaran untuk nomor ini, jadi belum tentu bukti transfer. Mohon dicek langsung.";
            $customer = $caption !== ''
                ? ($adaGambar ? 'Fotonya' : 'Filenya')." sudah kami terima kak 🙏\nKami teruskan ke tim kami dulu ya, nanti dibalas di chat ini."
                : "Lampirannya sudah kami terima, terima kasih Kak 🙏\n\nKalau ini bukti transfer, supervisor kami akan mengeceknya. Kalau Kakak mau menanyakan produk atau harga, tulis saja pertanyaannya di sini ya 😊";
        }

        // which of the bot's numbers (when there are several)
        if ($labelNomorBot !== '') {
            $admin = (string) preg_replace('/\n\n/', "\n📱 Lewat nomor bot: {$labelNomorBot}\n\n", $admin, 1);
        }

        return new self($order, $admin, $customer, $tandai, $catatanBukti);
    }

    /**
     * @param  Collection<int, LogOrder>  $orders
     */
    private static function pilihOrder(Collection $orders): ?LogOrder
    {
        $terakhir = fn (callable $cocok) => $orders->filter($cocok)->last();

        return $terakhir(fn (LogOrder $o) => $o->status_order === StatusOrder::Diacc)
            ?? $terakhir(fn (LogOrder $o) => $o->status_order === StatusOrder::MenungguKonfirmasi)
            ?? $terakhir(fn (LogOrder $o) => self::tertutupOtomatis($o) && self::umurJam($o) <= self::JAM_BUKTI_TERLAMBAT);
    }

    /**
     * Closed by the payment deadline: the BATAL OTOMATIS status, or the older way of writing it
     * (DIBATALKAN with "BATAL OTOMATIS" in the note).
     */
    private static function tertutupOtomatis(LogOrder $order): bool
    {
        return $order->status_order === StatusOrder::BatalOtomatis
            || ($order->status_order === StatusOrder::Dibatalkan && str_contains((string) $order->catatan_admin, 'BATAL OTOMATIS'));
    }

    /**
     * Hours since the order was closed automatically: from "BATAL OTOMATIS 2026-09-28 10:00" in
     * the note, else from the last change.
     */
    private static function umurJam(LogOrder $order): float
    {
        $waktu = preg_match('/BATAL OTOMATIS[^|]*?(\d{4}-\d{2}-\d{2} \d{2}:\d{2})/', (string) $order->catatan_admin, $m)
            ? Carbon::parse($m[1], 'Asia/Jakarta')
            : $order->updated_at;

        return $waktu === null ? 99999 : $waktu->diffInMinutes(now()) / 60;
    }

    private static function untukOrder(LogOrder $order, string $dari, string $caption, bool $terbaca, bool $batalOtomatis, bool $buktiSudahMasuk): string
    {
        $order->loadMissing('items');
        $produk = (int) $order->items->sum(fn ($i) => $i->qty * $i->harga_satuan) ?: (int) $order->total_produk;
        $ongkir = $order->ongkirCod() ? 0 : (int) $order->ongkir; // ongkir COD is not transferred
        $packing = (int) $order->biaya_packing;
        $rincian = $order->items->isEmpty()
            ? '-'
            : $order->items->map(fn ($i) => "• {$i->nama_produk} ({$i->qty}) — ".Rupiah::teks($i->qty * $i->harga_satuan))->implode("\n");
        $alamat = collect([$order->alamat_lengkap, $order->kelurahan, $order->kecamatan, $order->kota, $order->kode_pos])->filter()->implode(', ');

        $saran = match (true) {
            $batalOtomatis => '⚠️ Order ini sudah *DIBATALKAN otomatis* karena lewat batas bayar. Kalau dananya memang sudah masuk, order tetap bisa diproses: tekan *LUNAS* di panel → Pesanan.',
            $order->status_order === StatusOrder::Diacc => 'Kalau dana sudah masuk, tekan *LUNAS* di panel → Pesanan.',
            default => 'Order ini belum di-ACC: tekan *ACC* di panel → Pesanan untuk kirim invoice, lalu *LUNAS* kalau dana sudah masuk.',
        };

        return "💳 *BUKTI TRANSFER MASUK*\n\nOrder: *{$order->order_id}* ({$order->status_order->value})\nDari: {$dari}\n\n"
            ."📦 *Rincian pesanan*\n{$rincian}\n\n"
            .'💰 *Total produk:* '.Rupiah::teks((int) $order->total_produk)."\n"
            .($ongkir > 0 ? '🚚 *Ongkir:* '.Rupiah::teks($ongkir)."\n" : '')
            .($packing > 0 ? '📦 *Packing:* '.Rupiah::teks($packing)."\n" : '')
            .($order->ongkirCod() ? "🚚 *Ongkir:* COD ke kurir (tidak ikut transfer)\n" : '')
            .'🧾 *Total tagihan (yang harus ditransfer):* '.Rupiah::teks($produk + $packing + $ongkir)."\n"
            .'🚚 Kurir: '.($order->kurir_pilihan ?: '-').($order->berat_kg ? " · {$order->berat_kg} kg" : '')."\n"
            .($alamat !== '' ? "📍 {$alamat}\n" : '')
            .($caption !== '' ? "\n💬 Catatan customer: {$caption}\n" : '')
            .($terbaca ? '' : "\n⚠️ Lampiran tidak terbaca, cek langsung di chat customer.\n")
            ."\n— — —\n{$saran}"
            .($buktiSudahMasuk && ! $batalOtomatis ? "\n\n🛡️ Karena bukti sudah masuk, order ini *tidak* akan dibatalkan otomatis. Kalau buktinya tidak valid: tekan *TOLAK* di panel → Pesanan." : '');
    }
}
