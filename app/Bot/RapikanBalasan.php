<?php

namespace App\Bot;

use App\Support\AturanKirim;

/**
 * Adiva's structured answer turned into what is sent and saved: the WhatsApp text in up to
 * three bubbles, the CRM rating, the running order draft, and the order itself when it is
 * ready. It is n8n's "Rapikan Balasan", ported line by line (tests/Feature/RapikanBalasanTest
 * runs the original JavaScript beside it).
 *
 * It guards what the AI may get wrong: the product total is recounted from the lines, an
 * order is only recorded after the customer agreed to a recap with the same contents, an
 * employee price needs a verified employee, internal words ("ACC", "sistem") are written the
 * way a person would, and an auto-reply from the customer's WhatsApp Business is not answered.
 */
final class RapikanBalasan
{
    private const TAHAP_PROSPEK = ['BARU', 'TANYA_PRODUK', 'TANYA_HARGA', 'NEGO', 'SIAP_ORDER', 'CART_DITINGGAL'];

    private const JANGAN = '(jangan|gausah|ga usah|gak usah|nggak usah|ngga usah|tidak usah|tdk usah|ndak usah|gk usah)';

    private const MAAF = 'Mohon maaf Kak, boleh diulang pesannya ya 😊';

    /**
     * @param  array<string, mixed>  $out  the AI's output (Format Output)
     * @param  array<string, mixed>  $src  what "Siapkan Konteks" gave the AI (data_lama, pesan, chatId, …)
     * @param  list<array<string, mixed>>  $orders  the customer's orders (order_id, status_order)
     * @param  array<string, string|null>  $config  CONFIG_TOKO, key => value
     * @param  int  $sekarangMs  now, in milliseconds since the epoch
     * @return array<string, mixed>
     */
    public static function rapikan(array $out, array $src, array $orders, array $config, int $sekarangMs): array
    {
        $lama = Js::objek($src['data_lama'] ?? null) ? $src['data_lama'] : [];

        // Customer minta berhenti dihubungi -> tandai optout supaya tidak difollow-up lagi.
        $pesanCust = mb_strtolower(Js::rapat(Js::teks($src['pesan'] ?? '')));
        $mintaBerhenti = preg_match('/^(stop|berhenti|unsubscribe|unsub)[\s!.]*$/', $pesanCust)
            || preg_match('/\b(unsubscribe|hapus nomor (saya|aku|ku))\b/', $pesanCust)
            || preg_match('/\b(stop|berhenti)\b[^.?!]{0,15}\b(promo|broadcast|spam|kirim(in)? (promo|pesan|chat|broadcast)|chat(in)? (saya|aku)|hubungi|wa(in)? (saya|aku))\b/', $pesanCust)
            || preg_match('/\b'.self::JANGAN.'\b[^.?!]{0,20}\b(chat|hubungi|kirim(in)? (promo|pesan|chat)|promo|wa|spam)\b[^.?!]{0,15}\blagi\b/', $pesanCust)
            || preg_match('/\b'.self::JANGAN.'\b[^.?!]{0,10}\b(promo|spam|broadcast)\b/', $pesanCust);

        $teks = Js::teks(Js::atau($out['balasan'] ?? null, ''));
        $teks = str_replace(['\\r\\n', '\\n', '\\t'], ["\n", "\n", ' '], $teks);
        $teks = (string) preg_replace('/\*\*\*(.+?)\*\*\*/', '*$1*', $teks);
        $teks = (string) preg_replace('/\*\*(.+?)\*\*/', '*$1*', $teks);
        $teks = (string) preg_replace('/^#{1,6}\s*/m', '', $teks);
        $teks = (string) preg_replace('/^\s*[-*]\s+/m', '• ', $teks);
        $teks = Js::rapat((string) preg_replace('/\n{3,}/', "\n\n", $teks));
        if ($teks === '') {
            $teks = self::MAAF;
        }

        $wib = $sekarangMs + 7 * 3600 * 1000;
        $fmt = fn (int $ms) => gmdate('Y-m-d H:i', intdiv($ms, 1000));
        $jeda = array_key_exists('jeda_followup_hari', $out) ? Js::angka($out['jeda_followup_hari']) : NAN;
        $hari = Js::hingga($jeda) && $jeda > 0 ? $jeda : ($jeda === 0.0 ? 3650 : 2);
        $hariAman = min(max(1, $hari), 3650);
        $next = (int) ($wib + $hariAman * 24 * 60 * 60 * 1000);

        // Customer minta batal order tertentu (diisi AI)
        $batalId = preg_match('/ORD-[A-Z0-9-]+/', strtoupper(Js::teks(Js::atau($out['batal_order_id'] ?? null, ''))), $m) ? $m[0] : '';
        $isiAtauLama = fn (mixed $baru, mixed $lamaVal) => Js::benar($baru) && Js::rapat(Js::teks($baru)) !== '' ? Js::teks($baru) : Js::atau($lamaVal, '');

        $tahapAI = Js::atau($isiAtauLama($out['tahap_funnel'] ?? null, $lama['tahap_funnel'] ?? null), 'BARU');
        // Customer yang masih punya order berjalan tidak diturunkan ke tahap prospek.
        $adaOrderAktif = false;
        foreach ($orders as $r) {
            if (preg_match('/^(MENUNGGU_KONFIRMASI|DIACC|KARYAWAN|LUNAS|SIAP|DIKIRIM)$/i', Js::rapat(Js::teks(Js::atau($r['status_order'] ?? null, ''))))
                && Js::teks(Js::atau($r['order_id'] ?? null, '')) !== Js::teks(Js::atau($out['batal_order_id'] ?? null, ''))) {
                $adaOrderAktif = true;
                break;
            }
        }
        $orderSiap = Js::benar($out['order_siap'] ?? null);
        $tahapFinal = $adaOrderAktif && ! $orderSiap && in_array(strtoupper(Js::teks($tahapAI)), self::TAHAP_PROSPEK, true) ? 'ORDER_DIBUAT' : $tahapAI;
        $jamFU = 10;
        $nj = Js::angka($config['JEDA_FOLLOWUP_JAM'] ?? null);
        if ($nj > 0) {
            $jamFU = $nj;
        }
        $isProspek = in_array(strtoupper(Js::teks($tahapFinal)), self::TAHAP_PROSPEK, true);
        $nextFU = $jeda === 0.0 ? $next : ($isProspek ? (int) ($wib + $jamFU * 3600000) : null);

        // Nomor HP asli dari hasil resolusi LID
        $nomorFinal = Js::teks(Js::atau($src['nomor_wa'] ?? null, ''));
        $chatId = Js::teks(Js::atau($src['chatId'] ?? null, ''));
        if (str_ends_with($chatId, '@lid') && Js::benar($src['pn'] ?? null)) {
            $nomorFinal = (string) preg_replace('/\D/', '', explode('@', Js::teks($src['pn']))[0]);
        }
        $kontak = preg_match('/^62\d{7,}$/', $nomorFinal) ? 'wa.me/'.$nomorFinal : $chatId;

        // === Total produk dihitung ulang dari rincian item (bukan angka tulisan AI) ===
        $detail = [];
        if (is_array($out['order_items_detail'] ?? null) && array_is_list($out['order_items_detail'])) {
            foreach ($out['order_items_detail'] as $it) {
                $it = is_array($it) ? $it : [];
                $nama = mb_substr(Js::teks(Js::atau($it['nama'] ?? null, '')), 0, 120);
                if ($nama !== '') {
                    $q = Js::angka($it['qty'] ?? null);
                    $detail[] = ['nama' => $nama, 'qty' => self::bilangan(Js::benar($q) ? $q : 1), 'harga_satuan' => self::angka($it['harga_satuan'] ?? null)];
                }
            }
            $detail = array_slice($detail, 0, 60);
        }
        $sumDetail = array_sum(array_map(fn ($it) => $it['qty'] * $it['harga_satuan'], $detail));
        $totalAI = self::angka($out['order_total'] ?? null);
        $ongkirAI = self::angka($out['order_ongkir'] ?? null);
        $totalFinal = $totalAI;
        $peringatan = '';
        $tambah = function (string $baru) use (&$peringatan) {
            $peringatan = ($peringatan !== '' ? $peringatan."\n\n" : '').$baru;
        };
        if ($sumDetail > 0) {
            $totalFinal = $sumDetail;
            $ongkirIkutMasuk = $ongkirAI > 0 && $totalAI == $sumDetail + $ongkirAI;
            if ($totalAI > 0 && $totalAI != $sumDetail && ! $ongkirIkutMasuk) {
                $peringatan = '⚠️ Total produk yang ditulis AI ('.self::rp($totalAI).') tidak sama dengan hitungan rincian item ('.self::rp($sumDetail).'). Sistem memakai hitungan rincian. Mohon cek angka yang disebut ke customer sebelum ACC.';
            }
        }

        $kurirAI = Js::teks(Js::atau($out['kurir_pilihan'] ?? null, ''));
        $metodeAI = Js::teks(Js::atau($out['metode_bayar'] ?? null, ''));

        // === Pengiriman EKSPEDISI = ONGKIR COD (ongkir dibayar ke kurir, ekspedisi & nominal ditentukan CS). Paket gratis ongkir dikecualikan. ===
        $ongkirCod = AturanKirim::jadiCod($kurirAI, $metodeAI);
        $kurirFinal = $ongkirCod ? AturanKirim::kurirCod($kurirAI) : $kurirAI;

        // === Ongkir Rp0 untuk pesanan yang dikirim kurir (bukan COD): biasanya paket gratis ongkir ===
        if ($orderSiap && $kurirAI !== '' && ! $ongkirCod && ! preg_match('/AMBIL SENDIRI|PICKUP|KARYAWAN|KURIR MOTOR|ARMADA KANTOR/i', $kurirAI.' '.$metodeAI) && ! ($ongkirAI > 0)) {
            $tambah('ℹ️ Ongkir tercatat *Rp0* (kurir '.$kurirAI.'). Bot menganggap ini paket gratis ongkir — mohon pastikan sebelum ACC.');
        }

        if ($orderSiap && $ongkirCod) {
            $tambah('📦 Dikirim *EKSPEDISI — ONGKIR COD* ('.$kurirFinal.'). Ongkir dibayar customer ke kurir. Kalau mau mencantumkan ongkir di invoice sebagai info, tulis saat ACC, contoh: *ACC ORD-xxx 25000*.');
        }

        // === Kurir motor Bang Bana ===
        if ($orderSiap && preg_match('/KURIR MOTOR/i', $kurirAI)) {
            $tambah('🛵 Dikirim *KURIR MOTOR* (dari pabrik Lawang). '.($ongkirAI > 0
                ? 'Ongkir di order '.self::rp($ongkirAI).' = batas atas estimasi. Kalau ongkir pastinya beda, tulis angkanya saat ACC, contoh: *ACC ORD-xxx 25000*.'
                : 'Ongkir belum tercatat — tulis ongkirnya saat ACC, contoh: *ACC ORD-xxx 30000*.'));
        }

        // === Armada kantor ===
        if ($orderSiap && preg_match('/ARMADA KANTOR/i', $kurirAI)) {
            $minArm = self::angka($config['ARMADA_MIN_ORDER'] ?? null);
            $batasArm = self::angka($config['ARMADA_BATAS_TARIF'] ?? null) ?: 45000000;
            $belanjaArm = $totalFinal;
            $tambah('🚚 Dikirim *ARMADA KANTOR*. '
                .($ongkirAI > 0
                    ? 'Biaya kirim di order '.self::rp($ongkirAI).' sesuai tarif armada. Kalau beda, tulis angkanya saat ACC, contoh: *ACC ORD-xxx 450000*.'
                    : 'Biaya kirim belum ditetapkan'.($belanjaArm >= $batasArm ? ' (belanja mulai '.self::rp($batasArm).' dihitung admin)' : '').' — tulis saat ACC, contoh: *ACC ORD-xxx 500000*, atau *ACC ORD-xxx gratis*.')
                .($minArm > 0 && $belanjaArm > 0 && $belanjaArm < $minArm ? "\n⚠️ Belanja produk ".self::rp($belanjaArm).' masih DI BAWAH minimal armada ('.self::rp($minArm).') — mohon dicek sebelum ACC.' : ''));
        }

        // === Pengaman potong gaji: hanya untuk nomor yang terdaftar di tabel KARYAWAN ===
        $metodeBayar = $metodeAI;
        $terverifikasi = Js::benar($src['karyawan_terverifikasi'] ?? null);
        $tipeAI = Js::atau($isiAtauLama($out['tipe_customer'] ?? null, $lama['tipe_customer'] ?? null), 'BELUM_JELAS');
        $tipeFinal = strtoupper(Js::teks($tipeAI)) === 'KARYAWAN' && ! $terverifikasi ? 'BELUM_JELAS' : $tipeAI;
        if (preg_match('/POTONG GAJI/i', $metodeBayar) && ! $terverifikasi) {
            // PENTING: teks pengganti tidak boleh memuat kata "potong gaji"
            $metodeBayar = 'PERLU DICEK - minta bayar lewat gaji, nomor belum terdaftar karyawan';
            $tambah('⚠️ Customer ini minta *potong gaji*, tapi nomornya TIDAK terdaftar di tabel KARYAWAN. Order dicatat sebagai order biasa (menunggu ACC) dan tidak diteruskan ke bu Sarah. Mohon dicek dulu sebelum diproses.');
        }

        // === Pilihan balasan ditulis di akhir pesan (WhatsApp tidak lagi mendukung tombol) ===
        $pilihanB = [];
        if (is_array($out['pilihan_balasan'] ?? null) && array_is_list($out['pilihan_balasan'])) {
            $pilihanB = array_slice(array_values(array_filter(array_map(fn ($x) => Js::rapat(Js::teks($x)), $out['pilihan_balasan']), fn ($x) => $x !== '')), 0, 3);
        }
        $normP = fn (string $s) => trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)));
        if (count($pilihanB) >= 2) {
            $teksN = $normP($teks);
            $umum = ['yang', 'dulu', 'saja', 'untuk', 'dengan', 'atau', 'lain', 'lagi', 'mana'];
            $sudahAda = true;
            foreach ($pilihanB as $x) {
                $ks = array_filter(explode(' ', $normP($x)), fn ($k) => strlen($k) >= 4 && ! in_array($k, $umum, true));
                $ada = $ks === [] || array_filter($ks, fn ($k) => str_contains($teksN, $k)) !== [];
                $sudahAda = $sudahAda && $ada;
            }
            if (! $sudahAda) {
                $teks .= "\n\n👉 Balas: ".implode(' / ', array_map(fn ($x) => "*{$x}*", $pilihanB));
            }
        }
        // Nomor bu Sarah diambil dari CONFIG_TOKO (SARAH_WA), AI cukup menulis [NOMOR_SARAH]
        $noSarah = (string) preg_replace('/\D/', '', explode('@', Js::teks(Js::atau($src['sarah_wa'] ?? null, '')))[0]) ?: '6281252440646';
        $teks = str_replace('[NOMOR_SARAH]', $noSarah, $teks);

        // === Balasan otomatis WA Business / pesan ulang yang sama ===
        $normTeks = fn (mixed $s) => Js::rapat((string) preg_replace('/\s+/u', ' ', mb_strtolower(Js::teks(Js::atau($s, '')))));
        $teksIni = $normTeks($src['pesan'] ?? null);
        $pesanPanjang = mb_strlen($teksIni) >= 40;
        $pesanUlangSama = false;
        if ($pesanPanjang && $teksIni === $normTeks($lama['pesan_terakhir_customer'] ?? null)
            && ($t = self::waktuWib($lama['tgl_interaksi_terakhir'] ?? null)) !== null && $wib - $t < 3600000) {
            $pesanUlangSama = true;
        }
        $ditandai = ($out['balasan_otomatis'] ?? null) === true || mb_strtolower(Js::teks(Js::atau($out['balasan_otomatis'] ?? null, ''))) === 'true';
        $balasanOtomatis = $pesanPanjang && ! $orderSiap && ! $mintaBerhenti && ($pesanUlangSama || $ditandai);

        // === Draft pesanan berjalan ===
        $nomorEkor = Js::teks(Js::atau($nomorFinal, Js::atau($src['nomor_wa'] ?? null, Js::atau(explode('@', $chatId)[0], '0000'))));
        $orderIdBaru = 'ORD-'.gmdate('YmdHi', intdiv($wib, 1000)).gmdate('s', intdiv($wib, 1000)).'-'.mb_substr($nomorEkor, -4);
        $draftLama = $lama['draft_pesanan'] ?? null;
        if (is_string($draftLama) && trim($draftLama) !== '') {
            $draftLama = json_decode($draftLama, true);
        }
        if (! Js::objek($draftLama)) {
            $draftLama = [];
        }
        $dpAI = Js::objek($out['draft_pesanan'] ?? null) ? $out['draft_pesanan'] : null;
        $draft = array_merge(['status' => 'KOSONG', 'items' => []], $draftLama);
        $draftItemsBaru = false;
        $draftBerubah = false;
        if ($dpAI !== null && ! $balasanOtomatis) {
            $st = strtoupper(Js::rapat(Js::teks(Js::atau($dpAI['status'] ?? null, ''))));
            $itAI = is_array($dpAI['items'] ?? null) && array_is_list($dpAI['items'])
                ? array_slice(array_values(array_filter(array_map([self::class, 'normItem'], $dpAI['items']), fn ($it) => $it['nama'] !== '')), 0, 30)
                : [];
            if ($itAI !== []) {
                $draft['items'] = $itAI;
                $draftItemsBaru = true;
            } elseif ($st === 'KOSONG') {
                $draft['items'] = [];
            }
            // draft yang sudah jadi order (DICATAT) tidak diturunkan statusnya selama isi pesanannya tidak berubah
            $tetapDicatat = strtoupper(Js::teks(Js::atau($draftLama['status'] ?? null, ''))) === 'DICATAT'
                && ($itAI === [] || self::sig($itAI) === self::sig($draftLama['items'] ?? []));
            if (! $tetapDicatat && in_array($st, ['KOSONG', 'DISKUSI', 'MENUNGGU_KONFIRMASI', 'DIKONFIRMASI'], true)) {
                $draft['status'] = $st;
            }
            foreach (['cara_terima', 'tujuan_kirim', 'metode_bayar'] as $k) {
                $v = Js::rapat(($dpAI[$k] ?? null) === null ? '' : Js::teks($dpAI[$k]));
                if ($v !== '') {
                    $draft[$k] = mb_substr($v, 0, 120);
                }
            }
            $bg = self::angka($dpAI['budget'] ?? null);
            if ($bg > 0) {
                $draft['budget'] = $bg;
            }
            $draftBerubah = true;
        }
        // === Rencana ambil / bayar dari customer ===
        $rencanaAI = mb_substr(Js::rapat((string) preg_replace('/\s+/u', ' ', Js::teks(Js::atau($out['rencana_ambil'] ?? null, '')))), 0, 150);
        if ($rencanaAI !== '' && ! $balasanOtomatis) {
            $draft['rencana_ambil'] = $rencanaAI;
            $draftBerubah = true;
        }
        $rencanaFinal = $rencanaAI !== '' ? $rencanaAI : Js::teks(Js::atau($draft['rencana_ambil'] ?? null, ''));
        $ambilSendiriKurir = (bool) preg_match('/AMBIL SENDIRI|PICKUP/i', $kurirAI);

        // === Pengaman pencatatan order: order baru dicatat SETELAH customer menyetujui rekap akhir yang isinya sama ===
        $orderDitahan = false;
        if ($orderSiap && ! $balasanOtomatis) {
            $stLama = strtoupper(Js::teks(Js::atau($draftLama['status'] ?? null, '')));
            $itemsLama = $draftLama['items'] ?? null;
            $berubah = $detail !== [] && is_array($itemsLama) && array_is_list($itemsLama) && $itemsLama !== [] && self::sig($itemsLama) !== self::sig($detail);
            if ($berubah || ($stLama !== 'MENUNGGU_KONFIRMASI' && ! ($stLama === 'DICATAT' && Js::benar($itemsLama) && $itemsLama !== []))) {
                $orderDitahan = true;
                if ($detail !== []) {
                    $draft['items'] = $detail;
                }
                $draft['status'] = 'MENUNGGU_KONFIRMASI';
                if ($kurirAI !== '') {
                    $draft['cara_terima'] = $ambilSendiriKurir ? mb_substr($kurirAI, 0, 60) : 'DIKIRIM';
                    $draft['kurir'] = mb_substr($kurirAI, 0, 60);
                }
                if (Js::benar($out['kota'] ?? null) && ! $ambilSendiriKurir) {
                    $draft['tujuan_kirim'] = (Js::benar($out['kecamatan'] ?? null) ? Js::teks($out['kecamatan']).', ' : '').Js::teks($out['kota']);
                }
                if ($metodeBayar !== '') {
                    $draft['metode_bayar'] = mb_substr($metodeBayar, 0, 120);
                }
                $draftBerubah = true;
            }
        }
        $gantiId = $orderSiap && $orderDitahan === false && preg_match('/ORD-[A-Z0-9-]+/', strtoupper(Js::teks(Js::atau($out['ganti_order_id'] ?? null, ''))), $m) ? $m[0] : '';
        if ($orderSiap && ! $orderDitahan) {
            if ($detail !== []) {
                $draft['items'] = $detail;
            }
            $draft['status'] = 'DICATAT';
            $draft['order_id'] = $orderIdBaru;
            if ($kurirAI !== '') {
                $draft['kurir'] = mb_substr($kurirAI, 0, 60);
            }
            if ($kurirAI !== '' && (! Js::benar($draft['cara_terima'] ?? null) || $ambilSendiriKurir)) {
                $draft['cara_terima'] = $ambilSendiriKurir ? mb_substr($kurirAI, 0, 60) : 'DIKIRIM';
            }
            if (Js::benar($out['kota'] ?? null) && ! $ambilSendiriKurir) {
                $draft['tujuan_kirim'] = (Js::benar($out['kecamatan'] ?? null) ? Js::teks($out['kecamatan']).', ' : '').Js::teks($out['kota']);
            }
            if ($metodeBayar !== '') {
                $draft['metode_bayar'] = mb_substr($metodeBayar, 0, 120);
            }
            if ($gantiId !== '') {
                $draft['ganti_dari'] = $gantiId;
            } else {
                unset($draft['ganti_dari']);
            }
            $draftBerubah = true;
        }
        if ($batalId !== '' && ! $balasanOtomatis && ($draft['order_id'] ?? null) === $batalId) {
            $draft['status'] = 'BATAL';
            $draftBerubah = true;
        }
        $draft['items'] = array_slice(array_values(array_filter(
            array_map([self::class, 'normItem'], is_array($draft['items'] ?? null) && array_is_list($draft['items']) ? $draft['items'] : []),
            fn ($it) => $it['nama'] !== '',
        )), 0, 30);
        $draftTotal = array_sum(array_column($draft['items'], 'subtotal'));
        $draft['total_produk'] = $draftTotal;
        $draft['budget'] = self::angka($draft['budget'] ?? null);
        if ($draft['budget'] > 0) {
            $draft['sisa_budget'] = $draft['budget'] - $draftTotal;
        } else {
            unset($draft['sisa_budget']);
        }
        if ($draftBerubah) {
            $draft['diperbarui'] = $fmt($wib);
        }
        if ($orderDitahan && $draft['items'] !== []) {
            $baris = implode("\n", array_map(fn ($it) => '• '.$it['nama'].' ('.$it['qty'].') — '.self::rp($it['subtotal']), $draft['items']));
            $ambil = (bool) preg_match('/AMBIL SENDIRI|PICKUP/i', $kurirAI.' '.Js::teks(Js::atau($draft['cara_terima'] ?? null, '')));
            $ong = self::angka($out['order_ongkir'] ?? null);
            $rekap = "📦 *Detail Pesanan*\n".$baris."\n\n💰 *Total Produk:* ".self::rp($draftTotal);
            if (! $ambil && $kurirAI !== '') {
                $rekap .= $ong > 0 ? "\n🚚 *Ongkir ".$kurirAI.':* '.self::rp($ong)."\n🧾 *Total Bayar:* ".self::rp($draftTotal + $ong) : "\n🚚 *Kurir:* ".$kurirAI;
                $almt = implode(', ', array_filter(array_map(fn ($x) => Js::rapat(Js::teks(Js::atau($x, ''))), [
                    $out['nama_penerima'] ?? null, $out['alamat_lengkap'] ?? null, $out['kelurahan'] ?? null, $out['kecamatan'] ?? null, $out['kota'] ?? null,
                ]), fn ($x) => $x !== ''));
                if ($almt !== '') {
                    $rekap .= "\n📍 *Dikirim ke:* ".$almt;
                }
            } elseif (Js::benar($draft['cara_terima'] ?? null)) {
                $rekap .= "\n📍 *Cara terima:* ".preg_replace('/^AMBIL SENDIRI\s*-\s*/i', 'ambil sendiri di ', Js::teks($draft['cara_terima']));
            }
            if ($metodeBayar !== '' && ! preg_match('/PERLU DICEK/i', $metodeBayar)) {
                $rekap .= "\n💳 *Pembayaran:* ".self::labelBayar($metodeBayar);
            }
            if ($rencanaFinal !== '') {
                $rekap .= "\n🗓️ *Rencana:* ".$rencanaFinal;
            }
            if ($ambil && preg_match('/TRANSFER/i', $metodeBayar)) {
                $rekap .= "\n\n💡 Karena diambil langsung, bisa juga bayar di tempat saat ambil kalau lebih praktis — tinggal bilang ya kak.";
            }
            $teks = 'Siap kak, sebelum kami catat, ini rekap akhir pesanannya ya 😊 || '.$rekap.' || Kalau sudah pas, balas *ya* nanti langsung kami catat ya kak 🙏';
        }
        // Pengaman angka rekap: "Total Produk" di balasan harus sama dengan hitungan sistem (qty x harga satuan)
        if ($draftBerubah) {
            unset($draft['selisih_angka']);
        }
        $reTP = '/(Total Produk[:*\s]*)Rp\s?(\d[\d.,]*)/i';
        if ($draftItemsBaru && $draftTotal > 0 && preg_match($reTP, $teks, $mTP, PREG_OFFSET_CAPTURE) && self::angka($mTP[2][0]) != $draftTotal) {
            [$sumBaris, $nBaris] = self::bulletSebelum($teks, $mTP[0][1]);
            if ($nBaris > 0 && $sumBaris == $draftTotal) {
                // rincian per item sudah benar, hanya angka total yang meleset -> dibetulkan otomatis
                $teks = (string) preg_replace_callback($reTP, fn ($x) => $x[1].self::rp($draftTotal), $teks, 1);
                if (preg_match('/Ongkir[^\n]*?Rp\s?(\d[\d.,]*)/i', $teks, $mOng) && self::angka($mOng[1]) > 0) {
                    $teks = (string) preg_replace_callback('/(Total Bayar[:*\s]*)Rp\s?(\d[\d.,]*)/i', fn ($x) => $x[1].self::rp($draftTotal + self::angka($mOng[1])), $teks, 1);
                }
            } else {
                // tidak bisa dibetulkan otomatis -> diingatkan ke AI di chat berikutnya
                $draft['selisih_angka'] = 'Total produk di balasan terakhir '.self::rp(self::angka($mTP[2][0])).', hitungan sistem '.self::rp($draftTotal);
            }
        }
        // Rekap lengkap sudah ditunjukkan ke customer -> draft menunggu persetujuan
        if (! $balasanOtomatis && ($draft['status'] ?? null) !== 'DICATAT' && $draft['items'] !== [] && $draftTotal > 0 && ! Js::benar($draft['selisih_angka'] ?? null)
            && preg_match('/Total Produk[:*\s]*Rp\s?(\d[\d.,]*)/i', $teks, $mTP2, PREG_OFFSET_CAPTURE) && self::angka($mTP2[1][0]) == $draftTotal) {
            [$sum2, $n2] = self::bulletSebelum($teks, $mTP2[0][1]);
            if ($n2 > 0 && $sum2 == $draftTotal) {
                $draft['status'] = 'MENUNGGU_KONFIRMASI';
            }
        }
        if ($orderSiap && ! $orderDitahan && $draft['budget'] > 0 && $totalFinal > $draft['budget']) {
            $tambah('💸 Total produk '.self::rp($totalFinal).' MELEBIHI budget yang disebut customer ('.self::rp($draft['budget']).'). Mohon dicek sebelum ACC.');
        }

        $teks = self::bahasaManusia($teks);
        $teks = self::kurangiNama($teks, Js::teks(Js::atau($lama['nama'] ?? null, Js::atau($src['nama_wa'] ?? null, ''))), Js::teks(Js::atau($lama['balasan_terakhir_bot'] ?? null, '')));

        // === Gelembung chat: AI memisahkan balasan dengan penanda || (maksimal 3 gelembung) ===
        $gelembung = array_values(array_filter(array_map([Js::class, 'rapat'], preg_split('/\s*\|\|\s*/u', $teks) ?: []), fn ($x) => $x !== ''));
        if (count($gelembung) > 3) {
            $gelembung = [...array_slice($gelembung, 0, 2), implode("\n\n", array_slice($gelembung, 2))];
        }
        if ($gelembung === []) {
            $gelembung = ['Mohon maaf kak, boleh diulang pesannya ya 😊'];
        }
        $teks = implode("\n\n", $gelembung);
        $balasanKirim = $gelembung[count($gelembung) - 1];
        $gelembungAwal = array_slice($gelembung, 0, -1);

        // === Eskalasi terbuka: pertanyaan yang sedang ditanyakan ke supervisor ===
        $eskLama = self::json($lama['eskalasi_terbuka'] ?? null);
        $jwLama = self::json($lama['jawaban_supervisor'] ?? null);
        $tEsk = is_array($eskLama) ? self::waktuWib($eskLama['waktu'] ?? null) : null;
        $umurEsk = $tEsk === null ? 9999 : ($wib - $tEsk) / 3600000;
        $eskTerbuka = is_array($eskLama) && Js::benar($eskLama['alasan'] ?? null) && $umurEsk <= 72
            && ! (is_array($jwLama) && Js::teks(Js::atau($jwLama['waktu'] ?? null, '')) >= Js::teks(Js::atau($eskLama['waktu'] ?? null, '')));
        $eskalasi = Js::teks(Js::atau($lama['eskalasi_terbuka'] ?? null, ''));
        $eskDuplikat = false;
        $butuhAdminAI = Js::benar($out['butuh_admin'] ?? null);
        if (! $balasanOtomatis && $butuhAdminAI && $batalId === '' && Js::rapat(Js::teks(Js::atau($out['alasan_butuh_admin'] ?? null, ''))) !== '') {
            $alasanBaru = mb_substr(Js::rapat((string) preg_replace('/\s+/u', ' ', Js::teks($out['alasan_butuh_admin']))), 0, 300);
            if ($eskTerbuka) {
                // pertanyaan yang sama masih menunggu jawaban supervisor -> jangan kirim notifikasi lagi
                $a = array_unique(self::kata($eskLama['alasan']));
                $b = array_unique(self::kata($alasanBaru));
                $sama = count(array_intersect($b, $a));
                $eskDuplikat = $sama / (min(count($a), count($b)) ?: 1) >= 0.5;
            }
            if (! $eskDuplikat) {
                $eskalasi = json_encode([
                    'waktu' => $fmt($wib),
                    'alasan' => $alasanBaru,
                    'pesan' => mb_substr(Js::rapat((string) preg_replace('/\s+/u', ' ', Js::teks(Js::atau($src['pesan'] ?? null, '')))), 0, 300),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
            }
        }

        $namaLama = Js::teks(Js::atau($lama['nama'] ?? null, ''));
        $namaWa = Js::teks(Js::atau($src['nama_wa'] ?? null, ''));

        return [
            'kontak' => $kontak,
            'chatId' => $src['chatId'] ?? null,
            'nomor_wa' => $nomorFinal,
            'session' => $src['session'] ?? null,
            'label_nomor_bot' => Js::teks(Js::atau($src['_label_nomor_bot'] ?? null, '')),
            'balasan' => $teks,
            'balasan_kirim' => $balasanKirim,
            'gelembung_awal' => $gelembungAwal,
            'perlu_dibalas' => ! $balasanOtomatis,
            'balasan_otomatis' => $balasanOtomatis,
            // nama yang sudah tersimpan tidak ditimpa nama penerima paket (bisa orang lain)
            'nama' => $namaLama !== '' && $namaLama !== $namaWa ? $lama['nama'] : Js::atau(Js::rapat(Js::teks(Js::atau($out['nama_penerima'] ?? null, ''))), Js::atau($lama['nama'] ?? null, Js::atau($src['nama_wa'] ?? null, ''))),
            'tipe_customer' => $tipeFinal,
            'tahap_funnel' => $balasanOtomatis ? Js::atau($lama['tahap_funnel'] ?? null, $tahapFinal) : ($orderDitahan ? 'SIAP_ORDER' : $tahapFinal),
            'skor_prospek' => Js::atau($isiAtauLama($out['skor_prospek'] ?? null, $lama['skor_prospek'] ?? null), 'COLD'),
            'minat_produk' => $isiAtauLama($out['minat_produk'] ?? null, $lama['minat_produk'] ?? null),
            'lokasi_jualan' => $isiAtauLama($out['lokasi_jualan'] ?? null, $lama['lokasi_jualan'] ?? null),
            'estimasi_modal' => $isiAtauLama($out['estimasi_modal'] ?? null, $lama['estimasi_modal'] ?? null),
            'kota' => $isiAtauLama($out['kota'] ?? null, $lama['kota'] ?? null),
            'ringkasan_terakhir' => $balasanOtomatis ? Js::atau($lama['ringkasan_terakhir'] ?? null, '') : $isiAtauLama($out['ringkasan_terakhir'] ?? null, $lama['ringkasan_terakhir'] ?? null),
            'pesan_terakhir_customer' => $src['pesan'] ?? null,
            'jumlah_followup' => $balasanOtomatis ? self::bilangan(Js::angka(Js::atau($lama['jumlah_followup'] ?? null, 0))) : 0,
            'tgl_interaksi_terakhir' => $fmt($wib),
            'tgl_followup_berikutnya' => $balasanOtomatis
                ? Js::teks(Js::atau($lama['tgl_followup_berikutnya'] ?? null, ''))
                : ($nextFU !== null ? $fmt($nextFU) : ($orderDitahan ? $fmt((int) ($wib + $jamFU * 3600000)) : '')),
            'status_optout' => $mintaBerhenti ? 'YA' : Js::atau($lama['status_optout'] ?? null, 'TIDAK'),
            'butuh_admin' => ! $balasanOtomatis && ($butuhAdminAI || $batalId !== '') && ! $eskDuplikat ? 'YA' : 'TIDAK',
            'eskalasi_terbuka' => $eskalasi,
            'batal_order_id' => $batalId,
            'catatan' => $balasanOtomatis
                ? Js::teks(Js::atau($lama['catatan'] ?? null, ''))
                : Js::teks(Js::atau($out['alasan_butuh_admin'] ?? null, $batalId !== '' ? 'Customer minta pembatalan '.$batalId : '')),
            'order_siap' => $orderSiap && ! $orderDitahan,
            'order_id' => $orderIdBaru,
            'ganti_order_id' => $gantiId,
            'rencana_ambil' => $rencanaFinal,
            'draft_pesanan' => json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS),
            'order_items' => Js::teks(Js::atau($out['order_items'] ?? null, '')),
            'order_total' => Js::teks(Js::atau($totalFinal, '')),
            'peringatan_total' => $peringatan,
            'order_ongkir' => $ongkirAI && ! $ongkirCod ? (string) $ongkirAI : '',
            'order_packing' => (string) (AturanKirim::packing((float) str_replace(',', '.', (string) ($out['berat_kg'] ?? '')), (int) $totalFinal, $kurirAI, $metodeAI, $config) ?: ''),
            'nama_penerima' => Js::teks(Js::atau($out['nama_penerima'] ?? null, '')),
            'alamat_lengkap' => Js::teks(Js::atau($out['alamat_lengkap'] ?? null, '')),
            'kecamatan' => Js::teks(Js::atau($out['kecamatan'] ?? null, '')),
            'kode_pos' => Js::teks(Js::atau($out['kode_pos'] ?? null, '')),
            'berat_kg' => ($out['berat_kg'] ?? null) !== null ? Js::teks($out['berat_kg']) : '',
            'kurir_pilihan' => $kurirFinal,
            'metode_bayar' => $ongkirCod ? AturanKirim::METODE_COD : $metodeBayar,
            'no_hp' => Js::teks(Js::atau($out['no_hp'] ?? null, '')),
            'kelurahan' => Js::teks(Js::atau($out['kelurahan'] ?? null, '')),
            'kota_kirim' => Js::teks(Js::atau($out['kota'] ?? null, '')),
            'provinsi' => Js::teks(Js::atau($out['provinsi'] ?? null, '')),
            'kirim_gambar' => Js::benar($out['kirim_gambar'] ?? null),
            'url_gambar' => Js::teks(Js::atau($out['url_gambar'] ?? null, '')),
            'pilihan_balasan' => is_array($out['pilihan_balasan'] ?? null) && array_is_list($out['pilihan_balasan'])
                ? array_slice(array_map([Js::class, 'teks'], $out['pilihan_balasan']), 0, 3)
                : [],
            'order_items_detail' => $detail,
        ];
    }

    /**
     * The JavaScript `_angka`: an amount as the AI writes it. "35.000" and "Rp 35,000" are 35000,
     * "1,5" is 2 (rounded), anything else keeps only its digits.
     */
    public static function angka(mixed $v): int
    {
        if (is_int($v) || is_float($v)) {
            return is_float($v) && (is_nan($v) || is_infinite($v)) ? 0 : Js::bulat((float) $v);
        }

        $s = (string) preg_replace('/\s/u', '', (string) preg_replace('/rp/i', '', $v === null ? '' : Js::teks($v)));

        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $s)) {
            return (int) preg_replace('/[.,]/', '', $s);
        }

        if (preg_match('/^\d+([.,]\d{1,2})?$/', $s)) {
            return Js::bulat((float) str_replace(',', '.', $s));
        }

        return (int) preg_replace('/[^0-9]/', '', $s);
    }

    /**
     * "Rp1.245.000"
     */
    public static function rp(int|float $n): string
    {
        $n = Js::bulat(is_nan((float) $n) ? 0 : (float) $n);

        return 'Rp'.($n < 0 ? '-' : '').number_format(abs($n), 0, '', '.');
    }

    /**
     * @return array{nama: string, qty: int, harga_satuan: int, subtotal: int}
     */
    public static function normItem(mixed $it): array
    {
        $it = is_array($it) ? $it : [];
        $q = Js::angka($it['qty'] ?? null);
        $q = max(1, Js::bulat(Js::benar($q) ? $q : 1));
        $h = self::angka($it['harga_satuan'] ?? null);

        return ['nama' => mb_substr(Js::rapat(Js::teks(Js::atau($it['nama'] ?? null, ''))), 0, 120), 'qty' => $q, 'harga_satuan' => $h, 'subtotal' => $q * $h];
    }

    /**
     * What an order "is", for comparing two of them: qty x price of each line, sorted.
     */
    private static function sig(mixed $arr): string
    {
        $tanda = [];

        foreach (is_array($arr) && array_is_list($arr) ? $arr : [] as $it) {
            $it = is_array($it) ? $it : [];
            $q = Js::bulat(Js::angka($it['qty'] ?? null));
            $tanda[] = ($q ?: 1).'x'.self::angka($it['harga_satuan'] ?? null);
        }

        sort($tanda, SORT_STRING);

        return implode('|', $tanda);
    }

    /**
     * The sum of the "• … Rp…" lines just above $posisi (where "Total Produk" is written).
     *
     * @return array{0: int, 1: int} [sum, number of lines]
     */
    private static function bulletSebelum(string $teks, int $posisi): array
    {
        $baris = explode("\n", substr($teks, 0, $posisi));
        $sum = 0;
        $n = 0;
        // explode always gives at least one line; the last one holds "Total Produk"
        $i = count($baris) - 1;

        if (! preg_match('/^\s*•/u', $baris[$i])) {
            $i--;
        }

        for (; $i >= 0; $i--) {
            $l = Js::rapat($baris[$i]);

            if ($l === '') {
                continue;
            }

            if (! str_starts_with($l, '•') || ! preg_match('/Rp\s?(\d[\d.,]*)\s*$/', $l, $m)) {
                break;
            }

            $sum += self::angka($m[1]);
            $n++;
        }

        return [$sum, $n];
    }

    /**
     * Internal words the AI may have written, said the way a CS person would.
     */
    private static function bahasaManusia(string $teks): string
    {
        $ganti = [
            ['/\b([A-Za-z]{2,})(?:[ \t]+\1\b){2,}/i', '$1'],
            ['/(?:akan[ \t]+)?(?:ter|di)kirim(?:kan)?[ \t]+(?:secara[ \t]+)?otomatis(?:[ \t]+oleh[ \t]+sistem(?:[ \t]+kami)?)?/i', 'kami kirim'],
            ['/(?:secara[ \t]+)?otomatis[ \t]+oleh[ \t]+sistem(?:[ \t]+kami)?/i', ''],
            ['/[ \t]+oleh[ \t]+sistem(?:[ \t]+kami)?/i', ''],
            ['/\btercatat[ \t]+di[ \t]+sistem(?:[ \t]+kami)?/i', 'kami catat'],
            ['/\bdi[ \t]+sistem(?:[ \t]+kami)?/i', 'di catatan kami'],
            ['/\bsistem kami\b/i', 'tim kami'],
            ['/\bdi[ \t]?-?[ \t]?ACC\b/i', 'dikonfirmasi'],
            ['/\bmeng[ \t]?-?[ \t]?ACC\b/i', 'mengonfirmasi'],
            ['/\bACC\b/', 'konfirmasi'],
            ['/\bMENUNGGU_KONFIRMASI\b/', 'menunggu konfirmasi'],
            ['/\bDIACC\b/', 'sudah dikonfirmasi'],
            ['/[ \t]+(?:secara[ \t]+)?otomatis\b/i', ''],
            ['/[ \t]{2,}/', ' '],
            ['/[ \t]+([,.!?])/', '$1'],
        ];

        foreach ($ganti as [$pola, $jadi]) {
            $teks = (string) preg_replace($pola, $jadi, $teks);
        }

        return $teks;
    }

    /**
     * The customer's name is said at most once, and not again if the previous reply said it.
     */
    private static function kurangiNama(string $teks, string $nama, string $balasanLama): string
    {
        $kata = array_values(array_filter(preg_split('/\s+/u', Js::rapat($nama)) ?: [], fn ($k) => $k !== ''));
        $depan = $kata[0] ?? '';

        if (mb_strlen($depan) < 3 || preg_match('/^\d+$/', $depan)) {
            return $teks;
        }

        $esc = fn (string $x) => preg_quote($x, '/');
        $boleh = preg_match('/\b'.$esc($depan).'\b/i', $balasanLama) ? 0 : 1;
        $sisa = implode('\s+', array_map($esc, array_slice($kata, 1)));
        $pola = '/\b([Kk]ak)\s+'.$esc($depan).($sisa !== '' ? '(?:\s+'.$sisa.')?' : '').'\b/';

        if (preg_match_all($pola, $teks, $cocok, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
            return $teks;
        }

        // decided from the first match on (the one allowed name is the first), written from the
        // last back, so the offsets of the matches before it stay right
        $ubah = [];

        foreach ($cocok as $m) {
            [$utuh, $pos] = $m[0];

            if ($boleh > 0) {
                $boleh--;

                continue;
            }

            $awalKalimat = $pos === 0 || preg_match('/[.!?
]\s*$/', substr($teks, max(0, $pos - 3), min(3, $pos))) === 1;
            $ubah[] = [$pos, strlen($utuh), $awalKalimat ? $m[1][0] : 'kak'];
        }

        foreach (array_reverse($ubah) as [$pos, $panjang, $jadi]) {
            $teks = substr_replace($teks, $jadi, $pos, $panjang);
        }

        return $teks;
    }

    /**
     * @return list<string>
     */
    private static function kata(mixed $s): array
    {
        $stop = ['customer', 'menanyakan', 'tanya', 'nanya', 'jawaban', 'supervisor', 'menunggu', 'terkait', 'soal', 'minta', 'meminta', 'untuk', 'dengan', 'yang', 'masih', 'belum', 'kami', 'apakah', 'boleh', 'bisa', 'tidak', 'info', 'informasi', 'mengenai', 'perihal', 'progres', 'kabar', 'ketersediaan', 'sudah', 'dari', 'kakak'];
        $kata = preg_split('/\s+/', (string) preg_replace('/[^a-z0-9\s]/', ' ', mb_strtolower(Js::teks(Js::atau($s, ''))))) ?: [];

        return array_values(array_filter($kata, fn ($w) => strlen($w) >= 4 && ! in_array($w, $stop, true)));
    }

    /**
     * "2026-09-28 13:42" (WIB) as milliseconds on the same clock as $wib, or null.
     */
    private static function waktuWib(mixed $s): ?int
    {
        if (! preg_match('/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', Js::teks(Js::atau($s, '')), $m)) {
            return null;
        }

        return gmmktime((int) $m[4], (int) $m[5], 0, (int) $m[2], (int) $m[3], (int) $m[1]) * 1000;
    }

    /**
     * JSON.parse(String(s || '') || 'null'), null when it does not read.
     */
    private static function json(mixed $s): mixed
    {
        $t = Js::teks(Js::atau($s, ''));

        return $t === '' ? null : json_decode($t, true);
    }

    private static function labelBayar(string $m): string
    {
        return match (true) {
            (bool) preg_match('/BAYAR DI TEMPAT/i', $m) => 'Bayar di tempat saat ambil',
            (bool) preg_match('/POTONG GAJI/i', $m) => 'Potong gaji',
            (bool) preg_match('/TRANSFER/i', $m) => 'Transfer',
            default => mb_strtoupper(mb_substr(mb_strtolower($m), 0, 1)).mb_substr(mb_strtolower($m), 1),
        };
    }

    /**
     * A JavaScript number kept as PHP sees it: whole numbers as int.
     */
    private static function bilangan(float|int $n): int|float
    {
        return is_float($n) && floor($n) === $n && abs($n) < PHP_INT_MAX ? (int) $n : $n;
    }
}
