<?php

use App\Bot\PutuskanOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/*
 * PutuskanOrder is n8n's "Putuskan Order Baru" ported to PHP: it decides whether the order Adiva
 * just took is recorded, and what the supervisor is told with it. Each case runs through the
 * original JavaScript (tests/Fixtures/n8n/putuskan-order-baru.js) and the PHP, and both must agree.
 */

test('Putuskan Order Baru gives the same answer in PHP as the JavaScript it replaced', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        $this->markTestSkipped('Node tidak terpasang: perbandingan dengan JavaScript asli dilewati.');
    }

    $sekarang = CarbonImmutable::parse('2026-09-28 13:42:17', 'Asia/Jakarta')->getTimestampMs();
    $detail = [['nama' => 'Basreng Koin 1 kg Pedas', 'qty' => 10, 'harga_satuan' => 60000], ['nama' => 'Kripca 1,8 kg', 'qty' => 2, 'harga_satuan' => 88200]];
    $rbBawaan = [
        'order_id' => 'ORD-20260928134217-7890', 'order_items_detail' => $detail, 'order_items' => 'Basreng Koin 1 kg Pedas (10), Kripca 1,8 kg (2)',
        'order_total' => '776400', 'order_ongkir' => '38000', 'kurir_pilihan' => 'JNE', 'metode_bayar' => 'TRANSFER',
        'tgl_interaksi_terakhir' => '2026-09-28 13:42', 'peringatan_total' => '', 'ganti_order_id' => '',
    ];
    $order = fn (array $u) => [
        'order_id' => 'ORD-260927-001', 'status_order' => 'MENUNGGU_KONFIRMASI', 'tanggal' => '2026-09-27 10:00',
        'items' => 'Basreng Koin 1 kg Pedas (10), Kripca 1,8 kg (2)', 'items_detail' => json_encode($detail),
        'total_produk' => '776400', 'ongkir' => '38000', 'kurir_pilihan' => 'JNE', 'metode_bayar' => 'TRANSFER',
        'invoice_number' => null, 'catatan_admin' => null, ...$u,
    ];

    $daftar = [
        'the customer has no other order' => ['rows' => []],
        'the same contents still waiting for ACC: a double, not recorded' => ['rows' => [$order([])]],
        'the same contents waiting for payment (DIACC)' => ['rows' => [$order(['status_order' => 'DIACC', 'invoice_number' => 'INV/2026/09/007'])]],
        'the same contents, but made 3 days ago' => ['rows' => [$order(['tanggal' => '2026-09-25 10:00'])]],
        'the same contents already paid: recorded, with a warning' => ['rows' => [$order(['status_order' => 'LUNAS'])]],
        'the same contents on an employee order' => ['rbs' => ['metode_bayar' => 'POTONG GAJI'], 'rows' => [$order(['status_order' => 'KARYAWAN', 'metode_bayar' => 'POTONG GAJI'])]],
        'other contents while one is waiting' => ['rows' => [$order(['items_detail' => json_encode([['nama' => 'Molreng', 'qty' => 1, 'harga_satuan' => 17000]]), 'total_produk' => '17000'])]],
        'other contents while one is paid' => ['rows' => [$order(['status_order' => 'DIKIRIM', 'items_detail' => json_encode([['nama' => 'Molreng', 'qty' => 1, 'harga_satuan' => 17000]]), 'total_produk' => '17000'])]],
        'an employee with another order running' => ['rbs' => ['metode_bayar' => 'POTONG GAJI'], 'rows' => [$order(['status_order' => 'KARYAWAN', 'metode_bayar' => 'POTONG GAJI', 'items_detail' => json_encode([['nama' => 'Molreng', 'qty' => 1, 'harga_satuan' => 17000]])])]],
        'a finished order does not count' => ['rows' => [$order(['status_order' => 'SELESAI'])]],
        'a revision of an order not yet ACC\'d closes it' => ['rbs' => ['ganti_order_id' => 'ord-260927-001'], 'rows' => [$order(['items_detail' => json_encode([['nama' => 'Molreng', 'qty' => 1, 'harga_satuan' => 17000]]), 'total_produk' => '17000', 'catatan_admin' => 'dicatat Adiva'])]],
        'a revision of an ACC\'d order asks for a manual BATAL' => ['rbs' => ['ganti_order_id' => 'ORD-260927-001'], 'rows' => [$order(['status_order' => 'DIACC', 'invoice_number' => 'INV/2026/09/007', 'items_detail' => json_encode([['nama' => 'Molreng', 'qty' => 1, 'harga_satuan' => 17000]]), 'total_produk' => '17000'])]],
        'a revision of a paid order is left alone' => ['rbs' => ['ganti_order_id' => 'ORD-260927-001'], 'rows' => [$order(['status_order' => 'LUNAS', 'items_detail' => json_encode([['nama' => 'Molreng', 'qty' => 1, 'harga_satuan' => 17000]]), 'total_produk' => '17000'])]],
        'a revision of an order that is not there' => ['rbs' => ['ganti_order_id' => 'ORD-TIDAK-ADA'], 'rows' => []],
        'a warning about the total is carried into the notice' => ['rbs' => ['peringatan_total' => '⚠️ Total produk yang ditulis AI (Rp900.000) tidak sama dengan hitungan rincian item (Rp776.400).'], 'rows' => []],
        'two other orders running' => ['rows' => [$order(['items_detail' => json_encode([['nama' => 'Molreng', 'qty' => 1, 'harga_satuan' => 17000]]), 'total_produk' => '17000']), $order(['order_id' => 'ORD-260927-002', 'status_order' => 'LUNAS', 'items_detail' => json_encode([['nama' => 'Kripca', 'qty' => 1, 'harga_satuan' => 88200]]), 'total_produk' => '88200'])]],
        'the same lines written in another order' => ['rows' => [$order(['items_detail' => json_encode(array_reverse($detail))])]],
        'the same lines, different shipping' => ['rows' => [$order(['ongkir' => '25000'])]],
        'an order with no lines, only a text' => ['rbs' => ['order_items_detail' => [], 'order_items' => 'Molreng 500 gr (3)', 'order_total' => '51000'], 'rows' => [$order(['items_detail' => '[]', 'items' => 'Molreng 500 gr (3)', 'total_produk' => '51000', 'ongkir' => '38000'])]],
        'an order whose date cannot be read' => ['rows' => [$order(['tanggal' => null])]],
    ];

    $kasus = [];

    foreach ($daftar as $judul => $k) {
        $rb = [...$rbBawaan, ...($k['rbs'] ?? [])];
        $kasus[$judul] = [
            'sekarang_ms' => $sekarang,
            'json' => [],
            'node' => ['Rapikan Balasan' => [$rb]],
            'rows' => $k['rows'],
            'php' => fn () => PutuskanOrder::putuskan($rb, $k['rows'], $sekarang),
        ];
    }

    $berkas = tempnam(sys_get_temp_dir(), 'putuskan');
    file_put_contents($berkas, json_encode(array_values(array_map(fn ($k) => [...array_diff_key($k, ['php' => 1, 'rows' => 1]), 'json' => $k['rows'][0] ?? []], $kasus)), JSON_UNESCAPED_UNICODE));
    // the node reads its rows from $input.all(); the runner gives it one item, so rows go in the node map
    $isi = json_decode(file_get_contents($berkas), true);

    foreach (array_values($kasus) as $i => $k) {
        $isi[$i]['input'] = $k['rows'];
    }

    file_put_contents($berkas, json_encode($isi, JSON_UNESCAPED_UNICODE));
    $hasil = Process::run([$node, base_path('tests/Fixtures/n8n/jalankan.cjs'), base_path('tests/Fixtures/n8n/putuskan-order-baru.js'), $berkas]);
    unlink($berkas);

    expect($hasil->successful())->toBeTrue($hasil->errorOutput());

    $js = json_decode($hasil->output(), true);
    $judul = array_keys($kasus);

    foreach (array_values($kasus) as $i => $k) {
        $php = json_decode(json_encode($k['php'](), JSON_UNESCAPED_UNICODE), true);

        expect($php)->toEqual($js[$i], "kasus \"{$judul[$i]}\" berbeda");
    }
});
