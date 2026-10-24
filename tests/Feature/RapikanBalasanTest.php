<?php

use App\Bot\RapikanBalasan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/*
 * RapikanBalasan is n8n's "Rapikan Balasan" ported to PHP. Each case below runs through the
 * original JavaScript (tests/Fixtures/n8n/rapikan-balasan.js, with Node) and through the PHP,
 * and both must give the same answer.
 */

test('Rapikan Balasan gives the same answer in PHP as the JavaScript it replaced', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        $this->markTestSkipped('Node tidak terpasang: perbandingan dengan JavaScript asli dilewati.');
    }

    $sekarang = CarbonImmutable::parse('2026-09-28 13:42:17', 'Asia/Jakarta')->getTimestampMs();
    $lamaBawaan = [
        'nama' => 'Rina Maharani', 'tipe_customer' => 'RESELLER', 'tahap_funnel' => 'TANYA_HARGA', 'skor_prospek' => 'WARM', 'minat_produk' => 'Molreng',
        'lokasi_jualan' => null, 'estimasi_modal' => 1500000, 'kota' => 'Malang', 'ringkasan_terakhir' => 'Tanya harga Molreng',
        'pesan_terakhir_customer' => 'Molreng berapa?', 'balasan_terakhir_bot' => 'Rp17.000 kak', 'jumlah_followup' => 1,
        'tgl_interaksi_terakhir' => '2026-09-27 20:00', 'tgl_followup_berikutnya' => '2026-09-28 06:00', 'status_optout' => 'TIDAK',
        'butuh_admin' => 'TIDAK', 'catatan' => 'catatan lama', 'draft_pesanan' => null, 'eskalasi_terbuka' => null, 'jawaban_supervisor' => null,
    ];
    $kasus = [];

    // the cases: what changes from a plain answer to a new customer
    $daftar = (function (): array {
        $pesanan = [['nama' => 'Basreng Koin 1 kg Pedas', 'qty' => 10, 'harga_satuan' => 60000], ['nama' => 'Kripca 1,8 kg', 'qty' => 2, 'harga_satuan' => '88.200']];
        $draftSetuju = json_encode(['status' => 'MENUNGGU_KONFIRMASI', 'items' => [['nama' => 'Basreng Koin 1 kg Pedas', 'qty' => 10, 'harga_satuan' => 60000, 'subtotal' => 600000], ['nama' => 'Kripca 1,8 kg', 'qty' => 2, 'harga_satuan' => 88200, 'subtotal' => 176400]], 'total_produk' => 776400, 'diperbarui' => '2026-09-28 13:30']);
        $siap = ['order_siap' => true, 'order_items_detail' => $pesanan, 'order_total' => 776400, 'order_ongkir' => 38000, 'kurir_pilihan' => 'JNE', 'metode_bayar' => 'TRANSFER',
            'nama_penerima' => 'Rina Maharani', 'alamat_lengkap' => 'Jl. Kalpataru 18', 'kelurahan' => 'Dinoyo', 'kecamatan' => 'Lowokwaru', 'kota' => 'Malang', 'kode_pos' => '65144', 'berat_kg' => 12.5, 'no_hp' => '081234567890', 'provinsi' => 'Jawa Timur'];

        return [
            'a plain answer to a new customer' => ['out' => []],
            'markdown is written the WhatsApp way' => ['out' => ['balasan' => "### Daftar harga\n**Molreng** Rp17.000\n- Basreng\n* Kripca\\nbaris baru\n\n\n\nselesai ***penting***"]],
            'an empty answer asks to repeat' => ['out' => ['balasan' => '']],
            'two bubbles' => ['out' => ['balasan' => 'Halo kak 😊 || Molreng ada kak, Rp17.000']],
            'five bubbles become three' => ['out' => ['balasan' => 'satu || dua || tiga || empat || lima']],
            'choices not in the text are added' => ['out' => ['pilihan_balasan' => ['Mau order', 'Lihat harga reseller']]],
            'choices already in the text are not' => ['out' => ['balasan' => 'Mau order sekarang atau lihat harga reseller dulu kak?', 'pilihan_balasan' => ['Mau order', 'Lihat harga reseller']]],
            'choices that are only numbers are not added' => ['out' => ['pilihan_balasan' => ['1 pcs', '2 pcs']]],
            'Bu Sarah\'s number is filled in' => ['out' => ['balasan' => 'Hubungi bu Sarah di wa.me/[NOMOR_SARAH] ya kak']],
            'without SARAH_WA the old number is used' => ['out' => ['balasan' => 'wa.me/[NOMOR_SARAH]'], 'src' => ['sarah_wa' => '']],
            'stop' => ['out' => [], 'src' => ['pesan' => 'STOP']],
            'stop the promos' => ['out' => [], 'src' => ['pesan' => 'tolong stop kirim promo ya kak']],
            'do not chat me again' => ['out' => [], 'src' => ['pesan' => 'jangan chat saya lagi ya']],
            '"jangan kirim dulu" is not a stop' => ['out' => [], 'src' => ['pesan' => 'jangan kirim dulu ya kak, saya transfer besok']],
            'a WhatsApp Business auto-reply is not answered' => ['out' => ['balasan_otomatis' => true], 'src' => ['pesan' => 'Terima kasih telah menghubungi kami. Kami sedang di luar jam kerja, pesan Anda akan dibalas segera.'], 'lama' => true],
            'the same long message again within the hour' => ['out' => [], 'src' => ['pesan' => 'Kak saya mau tanya harga reseller untuk Molreng dan Basreng yang ukuran besar ya'], 'lama' => ['pesan_terakhir_customer' => 'Kak saya mau tanya harga reseller untuk Molreng dan Basreng yang ukuran besar ya', 'tgl_interaksi_terakhir' => '2026-09-28 13:10']],
            'an order the customer has not agreed to yet is held back for a recap' => ['out' => $siap],
            'an agreed recap with the same contents is recorded' => ['out' => [...$siap, 'ganti_order_id' => 'ord-260927-001'], 'lama' => ['draft_pesanan' => $draftSetuju]],
            'a recap whose contents changed is shown again' => ['out' => [...$siap, 'order_items_detail' => [['nama' => 'Basreng Koin 1 kg Pedas', 'qty' => 12, 'harga_satuan' => 60000]]], 'lama' => ['draft_pesanan' => $draftSetuju]],
            'the AI\'s total is wrong: the lines count' => ['out' => [...$siap, 'order_total' => 900000], 'lama' => ['draft_pesanan' => $draftSetuju]],
            'the AI put the shipping into the total: no warning' => ['out' => [...$siap, 'order_total' => 814400], 'lama' => ['draft_pesanan' => $draftSetuju]],
            'an expedition parcel pays packing, its shipping COD' => ['out' => $siap, 'lama' => ['draft_pesanan' => $draftSetuju], 'config' => ['PACKING_PER_KG' => '2000', 'PACKING_MINIMAL' => '5000']],
            'packing is free from PACKING_GRATIS_DI_ATAS' => ['out' => $siap, 'lama' => ['draft_pesanan' => $draftSetuju], 'config' => ['PACKING_PER_KG' => '2000', 'PACKING_MINIMAL' => '5000', 'PACKING_GRATIS_DI_ATAS' => '500.000']],
            'a parcel the shop sends free is not COD' => ['out' => [...$siap, 'kurir_pilihan' => 'JNE GRATIS ONGKIR', 'order_ongkir' => 0], 'lama' => ['draft_pesanan' => $draftSetuju], 'config' => ['PACKING_PER_KG' => '2000']],
            'a courier order with no shipping cost' => ['out' => [...$siap, 'order_ongkir' => 0], 'lama' => ['draft_pesanan' => $draftSetuju]],
            'the company\'s motorcycle courier' => ['out' => [...$siap, 'kurir_pilihan' => 'KURIR MOTOR', 'order_ongkir' => 30000], 'lama' => ['draft_pesanan' => $draftSetuju]],
            'the company\'s truck, under the minimum' => ['out' => [...$siap, 'kurir_pilihan' => 'ARMADA KANTOR', 'order_ongkir' => 0], 'lama' => ['draft_pesanan' => $draftSetuju], 'config' => ['ARMADA_MIN_ORDER' => '1.000.000']],
            'salary deduction from a number that is not an employee' => ['out' => [...$siap, 'metode_bayar' => 'POTONG GAJI', 'tipe_customer' => 'KARYAWAN'], 'lama' => ['draft_pesanan' => $draftSetuju]],
            'salary deduction from a verified employee' => ['out' => [...$siap, 'metode_bayar' => 'POTONG GAJI', 'tipe_customer' => 'KARYAWAN', 'kurir_pilihan' => 'AMBIL SENDIRI - Pabrik Lawang'], 'lama' => ['draft_pesanan' => $draftSetuju], 'src' => ['karyawan_terverifikasi' => true]],
            'a pickup order paid by transfer is held with a tip' => ['out' => [...$siap, 'kurir_pilihan' => 'AMBIL SENDIRI - Toko Sawojajar', 'order_ongkir' => 0, 'rencana_ambil' => '  Senin   sore  ']],
            'the AI keeps a draft: items, budget and plans' => ['out' => ['draft_pesanan' => ['status' => 'diskusi', 'items' => [['nama' => 'Molreng 500 gr', 'qty' => '3', 'harga_satuan' => 'Rp 17.000']], 'cara_terima' => 'AMBIL SENDIRI - Pabrik Lawang', 'metode_bayar' => 'BAYAR DI TEMPAT', 'budget' => '500rb'], 'rencana_ambil' => 'Sabtu pagi']],
            'a wrong total in the text with right lines is corrected' => ['out' => ['balasan' => "Rekapnya kak:\n• Molreng 500 gr (3) — Rp51.000\n• Basreng (2) — Rp120.000\n💰 *Total Produk:* Rp170.000\n🚚 *Ongkir JNE:* Rp20.000\n🧾 *Total Bayar:* Rp190.000", 'draft_pesanan' => ['status' => 'DISKUSI', 'items' => [['nama' => 'Molreng 500 gr', 'qty' => 3, 'harga_satuan' => 17000], ['nama' => 'Basreng', 'qty' => 2, 'harga_satuan' => 60000]]]]],
            'a wrong total with wrong lines is flagged for next time' => ['out' => ['balasan' => "• Molreng 500 gr (3) — Rp50.000\n💰 *Total Produk:* Rp50.000", 'draft_pesanan' => ['status' => 'DISKUSI', 'items' => [['nama' => 'Molreng 500 gr', 'qty' => 3, 'harga_satuan' => 17000]]]]],
            'a full recap in the text waits for the customer' => ['out' => ['balasan' => "• Molreng 500 gr (3) — Rp51.000\n💰 *Total Produk:* Rp51.000", 'draft_pesanan' => ['status' => 'DISKUSI', 'items' => [['nama' => 'Molreng 500 gr', 'qty' => 3, 'harga_satuan' => 17000]]]]],
            'a total over the customer\'s budget' => ['out' => $siap, 'lama' => ['draft_pesanan' => json_encode([...json_decode($draftSetuju, true), 'budget' => 500000])]],
            'internal words are said the way a person would' => ['out' => ['balasan' => 'Pesanan akan dikirim otomatis oleh sistem kami setelah di-ACC ya. Status MENUNGGU_KONFIRMASI, nanti DIACC. Tercatat di sistem kami, makin makin makin cepat , kak !']],
            'the name is said once, and not when the last reply said it' => ['out' => ['balasan' => 'Kak Rina, boleh kak. Kak Rina Maharani mau yang mana? Siap Kak Rina.'], 'lama' => ['nama' => 'Rina Maharani', 'balasan_terakhir_bot' => 'Halo kak']],
            'the name is not repeated after the last reply used it' => ['out' => ['balasan' => 'Kak Rina, boleh. Siap. Kak Rina mau yang mana?'], 'lama' => ['nama' => 'Rina Maharani', 'balasan_terakhir_bot' => 'Halo Kak Rina 😊']],
            'a question for the supervisor opens an escalation' => ['out' => ['butuh_admin' => true, 'alasan_butuh_admin' => 'Customer menanyakan potongan ongkir ke Surabaya untuk 30 bal']],
            'the same question again is not told twice' => ['out' => ['butuh_admin' => true, 'alasan_butuh_admin' => 'Customer tanya lagi potongan ongkir Surabaya 30 bal'], 'lama' => ['eskalasi_terbuka' => json_encode(['waktu' => '2026-09-28 11:00', 'alasan' => 'Customer menanyakan potongan ongkir ke Surabaya untuk 30 bal', 'pesan' => 'ada potongan?'])]],
            'an answered question does not stop a new one' => ['out' => ['butuh_admin' => true, 'alasan_butuh_admin' => 'Customer menanyakan potongan ongkir ke Surabaya untuk 30 bal'], 'lama' => ['eskalasi_terbuka' => json_encode(['waktu' => '2026-09-28 11:00', 'alasan' => 'Customer menanyakan potongan ongkir ke Surabaya untuk 30 bal']), 'jawaban_supervisor' => json_encode(['waktu' => '2026-09-28 12:00', 'jawaban' => 'bisa'])]],
            'a cancel request' => ['out' => ['batal_order_id' => 'tolong batalkan ORD-20260928101500-7890'], 'lama' => ['draft_pesanan' => json_encode(['status' => 'DICATAT', 'order_id' => 'ORD-20260928101500-7890', 'items' => [['nama' => 'Molreng', 'qty' => 1, 'harga_satuan' => 17000]]])]],
            'a running order keeps the customer out of the prospect stages' => ['out' => ['tahap_funnel' => 'TANYA_HARGA'], 'orders' => [['order_id' => 'ORD-1', 'status_order' => 'DIACC']]],
            'no follow-up at all when the AI says 0 days' => ['out' => ['jeda_followup_hari' => 0, 'tahap_funnel' => 'TIDAK_MINAT']],
            'no follow-up day from the AI: a prospect is greeted after JEDA_FOLLOWUP_JAM' => ['out' => ['jeda_followup_hari' => null], 'config' => ['JEDA_FOLLOWUP_JAM' => '6']],
            'the number behind an @lid' => ['out' => [], 'src' => ['chatId' => '99887766@lid', 'nomor_wa' => '99887766', 'pn' => '6281234567890@c.us']],
            'amounts written in every way' => ['out' => ['order_items_detail' => [['nama' => 'A', 'qty' => '2', 'harga_satuan' => 'Rp 1.250.000'], ['nama' => 'B', 'qty' => 1, 'harga_satuan' => '17,5'], ['nama' => 'C', 'qty' => 'x', 'harga_satuan' => 'Rp35,000'], ['nama' => '', 'qty' => 1, 'harga_satuan' => 1]], 'order_total' => '2.552.518']],
            'a recorded draft with the same contents stays recorded' => ['out' => ['draft_pesanan' => ['status' => 'DISKUSI', 'items' => [['nama' => 'Molreng', 'qty' => 2, 'harga_satuan' => 17000]]]], 'lama' => ['draft_pesanan' => json_encode(['status' => 'DICATAT', 'order_id' => 'ORD-X', 'items' => [['nama' => 'Molreng', 'qty' => 2, 'harga_satuan' => 17000]]])]],
            'the saved name is not replaced by the recipient\'s' => ['out' => ['nama_penerima' => 'Budi (suami)'], 'lama' => ['nama' => 'Rina Maharani'], 'src' => ['nama_wa' => 'rina']],
            'an employee label from the AI needs a verified number' => ['out' => ['tipe_customer' => 'KARYAWAN']],
        ];
    })();

    foreach ($daftar as $judul => $k) {
        $lama = ($k['lama'] ?? false) === false ? [] : [...$lamaBawaan, ...(is_array($k['lama']) ? $k['lama'] : [])];
        $src = [
            'chatId' => '6281234567890@c.us', 'nomor_wa' => '6281234567890', 'nama_wa' => 'Rina Maharani', 'pesan' => 'Halo kak, Molreng ada?',
            'session' => 'bangbana-waha', 'pn' => '', 'sarah_wa' => '6281298760646', '_label_nomor_bot' => '', 'karyawan_terverifikasi' => false,
            'data_lama' => $lama, ...($k['src'] ?? []),
        ];
        $out = [
            'balasan' => 'Ada kak 😊 Molreng Reguler 500 gr Rp17.000.', 'tipe_customer' => 'RESELLER', 'tahap_funnel' => 'TANYA_PRODUK', 'skor_prospek' => 'WARM',
            'minat_produk' => 'Molreng', 'kota' => 'Malang', 'ringkasan_terakhir' => 'Tanya Molreng', 'butuh_admin' => false, 'alasan_butuh_admin' => '',
            'order_siap' => false, 'jeda_followup_hari' => 2, 'pilihan_balasan' => [], 'kirim_gambar' => false, 'url_gambar' => '', 'order_items_detail' => [],
            ...$k['out'],
        ];
        $kasus[$judul] = [
            'sekarang_ms' => $sekarang,
            'json' => ['output' => $out],
            'node' => [
                'Siapkan Konteks' => [$src],
                'Pesan Gabungan' => [$src],
                'Ambil Order Customer' => $k['orders'] ?? [],
                'Ambil Config Ongkir' => collect($k['config'] ?? [])->map(fn ($nilai, $kunci) => ['kunci' => $kunci, 'nilai' => $nilai])->values()->all(),
            ],
            'php' => fn () => RapikanBalasan::rapikan($out, $src, $k['orders'] ?? [], $k['config'] ?? [], $sekarang),
        ];
    }

    $berkas = tempnam(sys_get_temp_dir(), 'rapikan');
    file_put_contents($berkas, json_encode(array_values(array_map(fn ($k) => array_diff_key($k, ['php' => 1]), $kasus)), JSON_UNESCAPED_UNICODE));
    $hasil = Process::run([$node, base_path('tests/Fixtures/n8n/jalankan.cjs'), base_path('tests/Fixtures/n8n/rapikan-balasan.js'), $berkas]);
    unlink($berkas);

    expect($hasil->successful())->toBeTrue($hasil->errorOutput());

    $js = json_decode($hasil->output(), true);
    $judul = array_keys($kasus);

    foreach (array_values($kasus) as $i => $k) {
        $php = json_decode(json_encode($k['php'](), JSON_UNESCAPED_UNICODE), true);

        foreach (['draft_pesanan', 'eskalasi_terbuka'] as $json) {
            // both are JSON text; the same object may be written with its keys in another order
            foreach ([&$php, &$js[$i]] as &$sisi) {
                $sisi[$json] = json_decode((string) $sisi[$json], true) ?? $sisi[$json];
            }
            unset($sisi);
        }

        expect($php)->toEqual($js[$i], "kasus \"{$judul[$i]}\" berbeda");
    }
});
