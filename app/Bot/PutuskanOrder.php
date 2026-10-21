<?php

namespace App\Bot;

/**
 * Whether the order Adiva has just taken is recorded, and what the supervisor should know with
 * it: n8n's "Putuskan Order Baru", ported line by line (tests/Feature/PutuskanOrderTest runs the
 * original JavaScript beside it).
 *
 * - the same contents as an order still waiting (ACC or payment), made in the last 2 days, is a
 *   double and is not recorded again
 * - the same as a paid or employee order is recorded, with a "kemungkinan dobel" warning
 * - another order running is mentioned, so the supervisor can decide
 * - a revision (ganti_order_id) closes the old order if it was not ACC'd yet, or says what to do
 */
final class PutuskanOrder
{
    private const MENUNGGU = ['MENUNGGU_KONFIRMASI', 'DIACC'];

    private const BELUM_BAYAR = ['MENUNGGU_KONFIRMASI', 'DIACC', 'KARYAWAN'];

    private const SUDAH_BAYAR = ['LUNAS', 'DIKIRIM'];

    /**
     * @param  array<string, mixed>  $rb  the answer RapikanBalasan made
     * @param  list<array<string, mixed>>  $rows  the customer's orders (n8n_log_order), oldest first
     * @return array<string, mixed>|null null: the order is a double and is not recorded
     */
    public static function putuskan(array $rb, array $rows, int $sekarangMs): ?array
    {
        $rows = array_values(array_filter($rows, fn ($o) => Js::benar($o['order_id'] ?? null)));
        $st = fn (array $o) => strtoupper(Js::teks(Js::atau($o['status_order'] ?? null, '')));
        $berjalan = array_values(array_filter($rows, fn ($o) => in_array($st($o), self::BELUM_BAYAR, true) || in_array($st($o), self::SUDAH_BAYAR, true)));
        $rp = fn (int|float $n) => RapikanBalasan::rp($n);
        $angka = fn (mixed $v) => (int) preg_replace('/[^0-9]/', '', $v === null ? '' : Js::teks($v));

        // === REVISI: customer mengganti order lama ===
        $gantiId = strtoupper(Js::rapat(Js::teks(Js::atau($rb['ganti_order_id'] ?? null, ''))));
        $lamaGanti = null;
        foreach ($gantiId !== '' ? $rows : [] as $o) {
            if (strtoupper(Js::teks(Js::atau($o['order_id'] ?? null, ''))) === $gantiId) {
                $lamaGanti = $o;
                break;
            }
        }
        $tutupOrderLama = $catatanLamaBaru = $catatanRevisi = $logRevisi = '';

        if ($gantiId !== '') {
            $stG = $lamaGanti !== null ? $st($lamaGanti) : '';
            $ringkasLama = $lamaGanti !== null
                ? mb_substr((string) preg_replace('/\s+/u', ' ', Js::teks(Js::atau($lamaGanti['items'] ?? null, '-'))), 0, 160).' — '.$rp($angka($lamaGanti['total_produk'] ?? null) + $angka($lamaGanti['ongkir'] ?? null))
                : '';

            if ($stG === 'MENUNGGU_KONFIRMASI') {
                $tutupOrderLama = $lamaGanti['order_id'];
                $catatanLamaBaru = (Js::benar($lamaGanti['catatan_admin'] ?? null) ? Js::teks($lamaGanti['catatan_admin']).' | ' : '').'DIGANTI oleh '.$rb['order_id'].' (revisi customer) '.Js::teks(Js::atau($rb['tgl_interaksi_terakhir'] ?? null, ''));
                $catatanRevisi = '♻️ *REVISI dari '.$lamaGanti['order_id']."* — order lama (belum di-ACC) sudah *ditutup otomatis*, cukup proses order ini.\nSebelumnya: ".$ringkasLama;
                $logRevisi = 'Revisi dari '.$lamaGanti['order_id'].' (order lama ditutup otomatis)';
            } elseif ($stG === 'DIACC' || $stG === 'KARYAWAN') {
                $catatanRevisi = '♻️ *REVISI dari '.$lamaGanti['order_id'].'* ('.$stG.(Js::benar($lamaGanti['invoice_number'] ?? null) ? ', invoice '.$lamaGanti['invoice_number'].' sudah terkirim' : '').").\nSebelumnya: ".$ringkasLama
                    ."\nKalau revisi ini disetujui, batalkan dulu yang lama: *BATAL ".$lamaGanti['order_id'].' revisi customer*, lalu ACC yang baru.';
                $logRevisi = 'Revisi dari '.$lamaGanti['order_id'].' ('.$stG.', perlu BATAL manual)';
            } else {
                $catatanRevisi = '♻️ Customer menyebut order ini *revisi dari '.$gantiId.'*'.($stG !== '' ? ' (status '.$stG.')' : ' (order lama tidak ditemukan)').'. Order lama TIDAK diubah sistem — mohon dicek manual sebelum ACC.';
                $logRevisi = 'Disebut revisi dari '.$gantiId.($stG !== '' ? ' ('.$stG.')' : ' (tidak ditemukan)');
            }
        }

        $tandaBaru = self::tanda($rb['order_items_detail'] ?? null, $rb['order_items'] ?? null, $rb['order_total'] ?? null, $rb['order_ongkir'] ?? null, $rb['kurir_pilihan'] ?? null, $rb['metode_bayar'] ?? null);
        $samaPersis = array_values(array_filter($berjalan, fn ($o) => self::tanda($o['items_detail'] ?? null, $o['items'] ?? null, $o['total_produk'] ?? null, $o['ongkir'] ?? null, $o['kurir_pilihan'] ?? null, $o['metode_bayar'] ?? null) === $tandaBaru));

        foreach ($samaPersis as $o) {
            if (in_array($st($o), self::MENUNGGU, true) && self::umurHari($o, $sekarangMs) <= 2) {
                return null; // order yang sama persis masih menunggu diproses -> tidak dicatat dobel
            }
        }

        $miripLama = $samaPersis !== [] ? $samaPersis[count($samaPersis) - 1] : null;
        $karyawan = (bool) preg_match('/POTONG GAJI/i', Js::teks(Js::atau($rb['metode_bayar'] ?? null, '')));

        // order lama yang direvisi tidak dihitung lagi sebagai "order lain yang berjalan"
        if ($gantiId !== '' && $lamaGanti !== null && ! in_array($st($lamaGanti), ['LUNAS', 'DIKIRIM'], true)) {
            $berjalan = array_values(array_filter($berjalan, fn ($o) => strtoupper(Js::teks($o['order_id'])) !== $gantiId));
        }

        $catatanOrder = $catatanLog = '';

        if ($berjalan !== []) {
            $belum = array_values(array_filter($berjalan, fn ($o) => in_array($st($o), self::BELUM_BAYAR, true)));
            $daftar = implode("\n", array_map(fn ($o) => '• *'.$o['order_id'].'* ('.$st($o).(in_array($st($o), self::SUDAH_BAYAR, true) ? ', sudah dibayar' : '').') — '.$rp($angka($o['total_produk'] ?? null) + $angka($o['ongkir'] ?? null)), $berjalan));

            if ($miripLama !== null) {
                $saran = '⚠️ *Kemungkinan dobel:* isi pesanan ini SAMA PERSIS dengan *'.$miripLama['order_id'].'* ('.$st($miripLama).', dibuat '.Js::teks(Js::atau($miripLama['tanggal'] ?? null, '-')).").\n"
                    .($karyawan
                        ? 'Kalau karyawannya memang pesan lagi, proses seperti biasa. Kalau ternyata dobel, minta supervisor menolak yang baru supaya potong gajinya tidak dobel: *TOLAK '.$rb['order_id'].' dobel dengan '.$miripLama['order_id'].'*'
                        : 'Kalau customer memang pesan lagi, proses seperti biasa. Kalau ternyata dobel, tolak yang baru: *TOLAK '.$rb['order_id'].' dobel dengan '.$miripLama['order_id'].'*');
            } elseif ($karyawan) {
                $saran = $belum !== []
                    ? 'Kalau order ini *revisi/pengganti*, minta supervisor membatalkan yang lama (*BATAL '.$belum[count($belum) - 1]['order_id'].'*) supaya potong gajinya tidak dobel.'
                    : 'Kalau order ini ternyata dobel, kabari supervisor sebelum potong gaji diproses.';
            } elseif ($belum !== []) {
                $saran = 'Kalau order baru ini *revisi/pengganti* order yang belum dibayar, batalkan dulu yang lama: *BATAL '.$belum[count($belum) - 1]['order_id']."*, lalu ACC yang baru.\nKalau ini *pesanan tambahan*, proses keduanya seperti biasa.";
            } else {
                $saran = "Order lamanya sudah dibayar. Kalau order baru ini ternyata *dobel atau revisi*, tanyakan dulu ke customer sebelum ACC.\nKalau ini *pesanan tambahan*, proses seperti biasa.";
            }

            $catatanOrder = '⚠️ *'.($karyawan ? 'Karyawan' : 'Customer')." ini masih punya order lain yang berjalan:*\n".$daftar."\n\n".$saran;
            $catatanLog = 'Masuk saat masih ada order lain berjalan: '.implode(', ', array_map(fn ($o) => $o['order_id'].' ('.$st($o).')', $berjalan))
                .($miripLama !== null ? '. Isinya sama persis dengan '.$miripLama['order_id'] : '');
        }

        if ($logRevisi !== '') {
            $catatanLog = $logRevisi.($catatanLog !== '' ? '. '.$catatanLog : '');
        }

        $catatanNotif = implode('', array_map(fn ($s) => $s."\n\n", array_values(array_filter([$catatanRevisi, Js::teks(Js::atau($rb['peringatan_total'] ?? null, '')), $catatanOrder], fn ($s) => $s !== ''))));

        return [
            'boleh_catat' => true,
            'order_berjalan' => array_map(fn ($o) => $o['order_id'], $berjalan),
            'sama_persis_dengan' => $miripLama !== null ? $miripLama['order_id'] : '',
            'catatan_order' => $catatanOrder,
            'catatan_log' => $catatanLog,
            'catatan_notif' => $catatanNotif,
            'ganti_order_id' => $gantiId,
            'tutup_order_lama' => $tutupOrderLama,
            'catatan_lama_baru' => $catatanLamaBaru,
        ];
    }

    private static function normNama(mixed $s): string
    {
        $t = mb_strtolower(Js::teks(Js::atau($s, '')));
        $t = (string) preg_replace('/kilogram|kilo/', 'kg', $t);
        $t = (string) preg_replace('/gram|gr\b/', 'g', $t);

        return (string) preg_replace('/[^a-z0-9]+/', '', $t);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parseDet(mixed $raw): array
    {
        $d = is_string($raw) ? json_decode($raw === '' ? '[]' : $raw, true) : $raw;

        return is_array($d) && array_is_list($d) ? $d : [];
    }

    private static function digit(mixed $v): int
    {
        return (int) preg_replace('/[^0-9]/', '', $v === null ? '' : Js::teks($v));
    }

    private static function tandaItem(mixed $det, mixed $teks): string
    {
        $t = array_map(function (array $it) {
            return self::normNama(Js::atau($it['nama'] ?? null, $it['name'] ?? null)).'#'.(Js::benar($q = Js::angka($it['qty'] ?? null)) ? self::bilangan($q) : 0).'#'.self::digit(Js::atau($it['harga_satuan'] ?? null, $it['harga'] ?? null));
        }, self::parseDet($det));
        sort($t, SORT_STRING);
        $gabung = implode('|', $t);

        return $gabung !== '' ? $gabung : self::normNama($teks);
    }

    private static function bilangan(float $q): int|float
    {
        return floor($q) === $q ? (int) $q : $q;
    }

    private static function tanda(mixed $det, mixed $teks, mixed $total, mixed $ongkir, mixed $kurir, mixed $bayar): string
    {
        return implode('~', [self::tandaItem($det, $teks), self::digit($total), self::digit($ongkir), self::normNama($kurir), self::normNama($bayar)]);
    }

    /**
     * Days since the order was made, from its "yyyy-mm-dd HH:mm" (WIB) date; 999 when unreadable.
     *
     * @param  array<string, mixed>  $o
     */
    private static function umurHari(array $o, int $sekarangMs): float
    {
        if (! preg_match('/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', Js::teks(Js::atau($o['tanggal'] ?? null, '')), $m)) {
            return 999;
        }

        $t = gmmktime((int) $m[4], (int) $m[5], 0, (int) $m[2], (int) $m[3], (int) $m[1]) * 1000 - 7 * 3600 * 1000;

        return ($sekarangMs - $t) / 86400000;
    }
}
