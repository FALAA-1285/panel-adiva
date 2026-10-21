<?php

use App\Bot\CekOngkir;
use App\Bot\KirimUlangInvoice;
use App\Bot\LokalCekOngkir;
use App\Bot\LokalKirimUlangInvoice;
use App\Bot\LokalPerintahOrder;
use App\Bot\N8nPerintahOrder;
use App\Bot\N8nTanyaAdiva;
use App\Bot\PengirimanGagal;
use App\Bot\PengirimWa;
use App\Bot\PerintahGagal;
use App\Bot\PerintahOrder;
use App\Bot\TanyaAdiva;
use App\Bot\TiruanPerintahOrder;
use App\Enums\AksiPesanan;
use App\Enums\StatusOrder;
use App\Enums\TahapFunnel;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\OrderItem;
use App\Models\Pesan;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PengirimRekam;

/*
 * BOT_DRIVER=lokal: the panel runs the order commands itself (docs/kontrak-n8n.md §8b).
 * The clock is frozen to Senin, 28 Sep 2026 13:42 WIB by demo mode.
 */

beforeEach(function () {
    foreach ([
        'REKENING' => 'BCA 123 a.n. Bang Bana', 'SESI_UTAMA' => 'bangbana-waha', 'BATAS_BAYAR_JAM' => '6',
        'JAM_PESAN_OTOMATIS' => '07:00-21:00', 'HARI_LIBUR_OTOMATIS' => 'Minggu',
        'MENGANTAR_API_KEY' => 'rahasia-kunci', 'MENGANTAR_KOTA_ASAL' => 'Lawang', 'MENGANTAR_PICKUP_ADDRESS_ID' => 'pk1',
    ] as $kunci => $nilai) {
        ConfigToko::create(['kunci' => $kunci, 'nilai' => $nilai]);
    }

    $this->app->bind(PerintahOrder::class, LokalPerintahOrder::class);
    $this->wa = PengirimRekam::pasang();
    $this->falah = User::factory()->falah()->create();
    $this->customer = CrmCustomer::factory()->create(['chat_id' => '6281234567890@c.us', 'nomor_wa' => '6281234567890', 'nama' => 'Rina Maharani']);

    // an order of two lines, sent by JNE, to be paid by transfer
    $this->buat = function (StatusOrder $status = StatusOrder::MenungguKonfirmasi, array $ubah = []) {
        $order = LogOrder::factory()->status($status)->create([
            'order_id' => 'ORD-260928-014', 'chat_id' => '6281234567890@c.us', 'nomor_wa' => '6281234567890', 'sesi_wa' => 'bangbana-waha',
            'crm_customer_id' => $this->customer->id, 'nama_penerima' => 'Rina Maharani', 'no_hp' => '081234567890',
            'total_produk' => 1245000, 'ongkir' => 38000, 'kurir_pilihan' => 'JNE', 'metode_bayar' => 'TRANSFER', 'ambil_sendiri' => false,
            'alamat_lengkap' => 'Jl. Kalpataru 18', 'kelurahan' => 'Dinoyo', 'kecamatan' => 'Lowokwaru', 'kota' => 'Malang', 'berat_kg' => '2.5',
            ...$ubah,
        ]);
        OrderItem::create(['log_order_id' => $order->id, 'urutan' => 1, 'nama_produk' => 'Basreng Koin 1 kg Pedas', 'qty' => 10, 'harga_satuan' => 60000, 'subtotal' => 600000]);
        OrderItem::create(['log_order_id' => $order->id, 'urutan' => 2, 'nama_produk' => 'Kripca 1,8 kg', 'qty' => 5, 'harga_satuan' => 129000, 'subtotal' => 645000]);

        return $order;
    };

    // Mengantar answers; $ubah replaces one answer, keyed "METODE /jalur"
    $this->mengantar = function (array $ubah = []) {
        Http::fake(['api-public.mengantar.com/*' => function (Request $r) use ($ubah) {
            $jalur = (string) preg_replace('#^.*/api/public/[^/]+#', '', (string) parse_url($r->url(), PHP_URL_PATH));
            $kunci = $r->method().' '.$jalur;

            if (array_key_exists($kunci, $ubah)) {
                return is_callable($ubah[$kunci]) ? $ubah[$kunci]($r) : $ubah[$kunci];
            }

            return match ($kunci) {
                'GET /address' => Http::response(['data' => [['_id' => 'pk1', 'PICKUP_DISTRICT' => 'LAWANG', 'PICKUP_NAME' => 'Pabrik Lawang']]]),
                'GET /address/search' => Http::response(['data' => [
                    ['_id' => 'aceh1', 'SUBDISTRICT_NAME' => 'DINOYO', 'DISTRICT_NAME' => 'KUTA', 'CITY_NAME' => 'ACEH BESAR', 'PROVINCE_NAME' => 'ACEH'],
                    ['_id' => 'dst1', 'SUBDISTRICT_NAME' => 'DINOYO', 'DISTRICT_NAME' => 'LOWOKWARU', 'CITY_NAME' => 'MALANG', 'PROVINCE_NAME' => 'JAWA TIMUR'],
                ]]),
                'GET /time' => Http::response(['data' => []]),
                'POST /time' => Http::response(['data' => ['_id' => 't1']]),
                'GET /order/estimate' => Http::response(['data' => [
                    'JNE' => ['estimatedPrice' => 38000, 'estimatedDate' => '2-3 hari'],
                    'SAPLite' => ['estimatedPrice' => 9975],
                    'SiCepat' => ['estimatedPrice' => 35000, 'estimatedSpecialPrice' => 30000],
                    'Lion' => ['unsupported' => true],
                ]]),
                'POST /order' => Http::response(['data' => [['ORDER_ID' => 'M123', '_id' => 'a1b2c3d4e5f6a1b2c3d4e5f6', 'cnote_no' => 'JNE999', 'estimatedPrice' => 38000]]]),
                'DELETE /order' => Http::response(['deletedCount' => 1]),
                default => Http::response([], 404),
            };
        }]);
    };
});

// ---------------------------------------------------------------- who does the work

test('BOT_DRIVER=lokal gives the panel what it has taken over, and leaves the rest with n8n', function () {
    config(['app.demo' => false, 'services.bot.driver' => 'lokal']);
    $this->app->offsetUnset(PerintahOrder::class);
    (new AppServiceProvider($this->app))->register();

    expect(app(PerintahOrder::class))->toBeInstanceOf(LokalPerintahOrder::class)
        ->and(app(CekOngkir::class))->toBeInstanceOf(LokalCekOngkir::class)
        ->and(app(KirimUlangInvoice::class))->toBeInstanceOf(LokalKirimUlangInvoice::class)
        ->and(app(TanyaAdiva::class))->toBeInstanceOf(N8nTanyaAdiva::class);
});

test('"langsung" still goes through n8n, and demo mode and the tests always use the stand-ins', function () {
    config(['app.demo' => false, 'services.bot.driver' => 'langsung']);
    $this->app->offsetUnset(PerintahOrder::class);
    (new AppServiceProvider($this->app))->register();

    expect(app(PerintahOrder::class))->toBeInstanceOf(N8nPerintahOrder::class);

    config(['app.demo' => true, 'services.bot.driver' => 'lokal']);

    expect(app(PerintahOrder::class))->toBeInstanceOf(TiruanPerintahOrder::class);
});

// ---------------------------------------------------------------- ACC and the invoice

test('ACC makes the invoice, keeps the PDF and sends it to the customer', function () {
    $order = ($this->buat)();

    $this->actingAs($this->falah)->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'ACC'])->assertSessionHasNoErrors();

    $order->refresh();
    $pdf = DB::table('invoice_pdf')->where('log_order_id', $order->id)->first();

    expect($order->status_order)->toBe(StatusOrder::Diacc)
        ->and($order->invoice_number)->toBe('INV/2026/09/001')
        ->and(stream_get_contents($pdf->isi))->toStartWith('%PDF')
        ->and($pdf->nama_file)->toBe('INV-2026-09-001.pdf')
        ->and($this->wa->terkirim)->toHaveCount(1)
        ->and($this->wa->terkirim[0])->toMatchArray(['sesi' => 'bangbana-waha', 'chat_id' => '6281234567890@c.us', 'berkas' => 'INV-2026-09-001.pdf'])
        ->and($this->wa->terkirim[0]['teks'])->toContain('Pesanan *ORD-260928-014* sudah kami setujui ✅', 'INV/2026/09/001', 'Pesanan kami tahan 6 jam kerja')
        ->and(Pesan::query()->sole()->only(['pengirim', 'lampiran_keterangan', 'status_kirim']))->toMatchArray(['lampiran_keterangan' => 'Invoice PDF', 'status_kirim' => 'TERKIRIM'])
        ->and(ActivityLog::query()->sole()->aksi)->toBe('ACC order');
});

test('what other workflows read from the order note keeps n8n\'s words', function () {
    $order = ($this->buat)();
    $kendali = app(PerintahOrder::class);

    $kendali->jalankan(AksiPesanan::Acc, $order, $this->falah, '25000');
    $kendali->jalankan(AksiPesanan::Lunas, $order->refresh(), $this->falah);

    // Batas Bayar, Laporan Harian and the AI's Cek Order tool find "ACC …" and "LUNAS …" in it
    expect($order->refresh()->catatan_admin)->toBe('ACC 2026-09-28 13:42 ongkir Rp25.000 | LUNAS 2026-09-28 13:42')
        ->and($order->ongkir)->toBe(25000);
});

test('a new ongkir on an order already ACC\'d is a revision: same number, invoice sent again', function () {
    $order = ($this->buat)(StatusOrder::Diacc, ['invoice_number' => 'INV/2026/09/007', 'diacc_at' => now()]);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Acc, $order, $this->falah, '40000');

    expect($order->refresh()->invoice_number)->toBe('INV/2026/09/007')
        ->and($order->catatan_admin)->toStartWith('REVISI ONGKIR 2026-09-28 13:42 ongkir Rp40.000')
        ->and($this->wa->terkirim[0]['teks'])->toContain('Ada *pembaruan ongkir* untuk pesanan *ORD-260928-014*');
});

test('ACC without an account number to pay to is refused and changes nothing', function () {
    ConfigToko::query()->where('kunci', 'REKENING')->delete();
    $order = ($this->buat)();

    $this->actingAs($this->falah)->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'ACC'])
        ->assertSessionHasErrors(['aksi' => 'Rekening belum diisi. Isi dulu di panel (Pengaturan → Rekening), baru jalankan ACC lagi.']);

    expect($order->refresh()->status_order)->toBe(StatusOrder::MenungguKonfirmasi)
        ->and($this->wa->terkirim)->toBeEmpty();
});

test('a PDF WhatsApp will not take goes out as text, and the supervisor is told', function () {
    $wa = new class implements PengirimWa
    {
        /** @var list<string> */
        public array $teks = [];

        public function kirimTeks(string $sesi, string $chatId, string $teks): string
        {
            $this->teks[] = $teks;

            return 'wa-'.count($this->teks);
        }

        public function kirimGambar(string $sesi, string $chatId, string $url, string $mimetype, string $caption): string
        {
            return '';
        }

        public function kirimBerkas(string $sesi, string $chatId, string $isi, string $mimetype, string $namaFile, string $caption): string
        {
            throw new PengirimanGagal('file ditolak');
        }
    };
    $this->app->instance(PengirimWa::class, $wa);
    $order = ($this->buat)();

    app(PerintahOrder::class)->jalankan(AksiPesanan::Acc, $order, $this->falah);

    expect($order->refresh()->status_order)->toBe(StatusOrder::Diacc)
        ->and($wa->teks)->toHaveCount(1)
        ->and($wa->teks[0])->toContain('🧾 *INVOICE INV/2026/09/001*', '• Basreng Koin 1 kg Pedas (10 x Rp60.000) — Rp600.000', '*Total bayar: Rp1.283.000*', 'BCA 123 a.n. Bang Bana')
        ->and(Notifikasi::query()->where('jenis', 'INVOICE_GAGAL')->sole()->judul)->toBe('Invoice PDF gagal, dikirim versi teks');
});

test('when even the text is refused the supervisor is told the invoice did not reach the customer', function () {
    PengirimRekam::pasang(tolak: 'WAHA tidak bisa dihubungi');
    $order = ($this->buat)();

    app(PerintahOrder::class)->jalankan(AksiPesanan::Acc, $order, $this->falah);

    expect($order->refresh()->status_order)->toBe(StatusOrder::Diacc)
        ->and(Notifikasi::query()->where('jenis', 'INVOICE_GAGAL')->sole()->judul)->toBe('Invoice PDF gagal dibuat');
});

test('the chat id is the second try when the phone number is refused', function () {
    $order = ($this->buat)(ubah: ['chat_id' => '98765@lid']);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Acc, $order, $this->falah);

    // the number 6281234567890@c.us comes first, the @lid chat id after it
    expect($this->wa->terkirim[0]['chat_id'])->toBe('6281234567890@c.us');
});

// ---------------------------------------------------------------- messages to the customer

test('TOLAK, BATAL, SIAP, SELESAI and the others tell the customer in n8n\'s words', function (AksiPesanan $aksi, StatusOrder $dari, array $ubah, ?string $catatan, string $harusAda, StatusOrder $jadi) {
    $order = ($this->buat)($dari, $ubah);

    app(PerintahOrder::class)->jalankan($aksi, $order, $this->falah, $catatan);

    expect($order->refresh()->status_order)->toBe($jadi)
        ->and($this->wa->terkirim)->toHaveCount(1)
        ->and($this->wa->terkirim[0]['teks'])->toContain($harusAda)
        ->and($this->wa->terkirim[0]['chat_id'])->toBe('6281234567890@c.us');
})->with([
    'TOLAK with a reason' => [AksiPesanan::Tolak, StatusOrder::MenungguKonfirmasi, [], 'stok habis', "Mohon maaf Kak Rina Maharani, pesanan ORD-260928-014 belum bisa kami proses.\nAlasannya: stok habis", StatusOrder::Ditolak],
    'BATAL before payment' => [AksiPesanan::Batal, StatusOrder::Diacc, ['invoice_number' => 'INV/2026/09/007'], null, 'Invoice sebelumnya tidak berlaku lagi', StatusOrder::Dibatalkan],
    'LUNAS of a courier order' => [AksiPesanan::Lunas, StatusOrder::Diacc, ['invoice_number' => 'INV/2026/09/007', 'ambil_sendiri' => true, 'kurir_pilihan' => 'AMBIL SENDIRI - Toko Sawojajar', 'metode_bayar' => 'TRANSFER'], null, 'siapkan dulu ya. Nanti kami kabari kalau sudah siap diambil di Toko Sawojajar', StatusOrder::Lunas],
    'SIAP with a note' => [AksiPesanan::Siap, StatusOrder::Lunas, ['ambil_sendiri' => true, 'kurir_pilihan' => 'AMBIL SENDIRI - Toko Sawojajar'], 'bawa KTP', 'sudah *siap diambil* ✅', StatusOrder::Lunas],
    'KIRIM with a waybill' => [AksiPesanan::Kirim, StatusOrder::Lunas, [], 'JNE1234567890', 'Nomor resi: *JNE1234567890*', StatusOrder::Dikirim],
    'KIRIM by the company\'s courier' => [AksiPesanan::Kirim, StatusOrder::Lunas, ['kurir_pilihan' => 'KURIR MOTOR'], 'Budi', "sedang diantar kurir Bang Bana 🛵\nKurir: Budi", StatusOrder::Dikirim],
    'SELESAI' => [AksiPesanan::Selesai, StatusOrder::Dikirim, [], null, 'Terima kasih banyak Kak Rina Maharani 🙏', StatusOrder::Selesai],
]);

test('the invoice number stands beside the order number once the order has one', function () {
    $order = ($this->buat)(StatusOrder::Diacc, ['invoice_number' => 'INV/2026/09/007']);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Batal, $order, $this->falah);

    expect($this->wa->terkirim[0]['teks'])->toContain('pesanan *ORD-260928-014 / INV/2026/09/007* sudah kami batalkan');
});

test('the company\'s own courier carries no waybill', function () {
    $order = ($this->buat)(StatusOrder::Lunas, ['kurir_pilihan' => 'ARMADA KANTOR']);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Kirim, $order, $this->falah, 'Pak Joko');

    expect($order->refresh()->no_resi)->toBeNull()
        ->and($order->catatan_admin)->toBe('DIKIRIM 2026-09-28 13:42 armada kantor - Pak Joko');
});

test('TUTUP closes the order without telling the customer and cools the CRM', function () {
    $order = ($this->buat)(StatusOrder::Diacc);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Tutup, $order, $this->falah, 'dialihkan ke CS lain');

    expect($order->refresh()->status_order)->toBe(StatusOrder::Ditutup)
        ->and($this->wa->terkirim)->toBeEmpty()
        ->and($this->customer->refresh()->catatan)->toBe('order ORD-260928-014 ditutup tanpa chat ke customer - dialihkan ke CS lain')
        ->and($this->customer->jumlah_followup)->toBe(0);
});

test('SELESAI moves the customer to ORDER_DIBUAT and stamps when', function () {
    $order = ($this->buat)(StatusOrder::Dikirim);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Selesai, $order, $this->falah);

    expect($this->customer->refresh()->tahap_funnel)->toBe(TahapFunnel::OrderDibuat)
        ->and($this->customer->tahap_sejak)->toHaveKey('ORDER_DIBUAT')
        ->and($this->customer->catatan)->toBe('transaksi ORD-260928-014 selesai');
});

test('a message WhatsApp refuses does not undo the command; the supervisor is told', function () {
    PengirimRekam::pasang(tolak: 'WAHA tidak bisa dihubungi');
    $order = ($this->buat)(StatusOrder::Diacc);

    $this->actingAs($this->falah)->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'TOLAK', 'catatan' => 'stok habis'])->assertSessionHasNoErrors();

    expect($order->refresh()->status_order)->toBe(StatusOrder::Ditolak)
        ->and(Notifikasi::query()->where('jenis', 'BALASAN_GAGAL')->sole()->detail)->toContain('ORD-260928-014', 'WAHA tidak bisa dihubungi');
});

test('a command is one run of path A on Monitoring', function () {
    $order = ($this->buat)(StatusOrder::Diacc);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Batal, $order, $this->falah);

    expect(WorkflowEvent::query()->sole()->only(['jenis', 'judul', 'data']))->toBe(['jenis' => 'EKSEKUSI', 'judul' => 'Perintah admin', 'data' => ['jalur' => 'A', 'pesan' => 1, 'sumber' => 'panel']]);
});

// ---------------------------------------------------------------- shipping through Mengantar

test('LUNAS of a courier order books the shipment, sends the waybill and notifies', function () {
    ($this->mengantar)();
    $order = ($this->buat)(StatusOrder::Diacc, ['invoice_number' => 'INV/2026/09/007', 'catatan_admin' => 'ACC 2026-09-28 10:00']);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Lunas, $order, $this->falah);

    $order->refresh();

    expect($order->status_order)->toBe(StatusOrder::Dikirim)
        ->and($order->no_resi)->toBe('JNE999')
        ->and($order->kurir_final)->toBe('JNE')
        ->and($order->dikirim_at)->not->toBeNull()
        ->and($order->catatan_admin)->toContain('ACC 2026-09-28 10:00 | LUNAS 2026-09-28 13:42 | DIKIRIM 2026-09-28 13:42 resi JNE999 (MENGANTAR ORDER M123 #a1b2c3d4e5f6a1b2c3d4e5f6, JNE, jemput 2026-09-29 13:00)')
        ->and(collect($this->wa->terkirim)->pluck('teks')->last())->toContain('Nomor resi: *JNE999*', 'dijemput kurir besok (Selasa) sekitar jam 13.00')
        ->and(Notifikasi::query()->where('jenis', 'PENGIRIMAN')->sole()->judul)->toBe('Pengiriman ORD-260928-014');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/order')
        && $r['courier'] === 'JNE' && $r['orders'][0]['customerPhone'] === '081234567890'
        && $r['orders'][0]['customerAddressDataId'] === 'dst1' && $r['orders'][0]['weight'] === 2.5 && $r['orders'][0]['quantity'] === 15);
});

test('a destination in another region of the same name is not picked', function () {
    ($this->mengantar)();
    $order = ($this->buat)(StatusOrder::Lunas);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Kirim, $order, $this->falah);

    // Dinoyo, Lowokwaru, Malang (East Java) and not Dinoyo in Aceh
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/order') && $r['orders'][0]['customerAddressDataId'] === 'dst1');
});

test('KIRIM without a waybill books it; an order that is not a courier\'s is left alone', function (array $ubah, bool $dipesan) {
    ($this->mengantar)();
    $order = ($this->buat)(StatusOrder::Diacc, $ubah);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Lunas, $order, $this->falah);

    $dipanggil = collect(Http::recorded())->contains(fn ($par) => $par[0]->method() === 'POST' && str_ends_with($par[0]->url(), '/order'));

    expect($dipanggil)->toBe($dipesan);
})->with([
    'a courier order' => [[], true],
    'self pickup' => [['ambil_sendiri' => true, 'kurir_pilihan' => 'AMBIL SENDIRI - Toko Sawojajar'], false],
    'the company\'s motorcycle' => [['kurir_pilihan' => 'KURIR MOTOR'], false],
    'already has a waybill' => [['no_resi' => 'JNE111'], false],
]);

test('with the booking switched off nothing is booked and KIRIM asks for the waybill', function () {
    ConfigToko::create(['kunci' => 'MENGANTAR_BUAT_ORDER', 'nilai' => 'MATI']);
    Http::fake();
    $order = ($this->buat)(StatusOrder::Lunas);

    $this->actingAs($this->falah)->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'KIRIM'])
        ->assertSessionHasErrors(['aksi' => 'Nomor resinya belum disertakan. Contoh: JNE1234567890']);

    Http::assertNothingSent();
    expect($order->refresh()->status_order)->toBe(StatusOrder::Lunas);
});

test('UJI mode only previews the shipment and books nothing', function () {
    ConfigToko::create(['kunci' => 'MENGANTAR_BUAT_ORDER', 'nilai' => 'UJI']);
    ($this->mengantar)();
    $order = ($this->buat)(StatusOrder::Lunas);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Kirim, $order, $this->falah);

    expect($order->refresh()->status_order)->toBe(StatusOrder::Lunas)
        ->and(Notifikasi::query()->where('jenis', 'PENGIRIMAN')->sole()->detail)->toContain('MODE UJI', 'TIDAK dibuat', 'Daerah Mengantar: DINOYO, LOWOKWARU, MALANG');

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/order'));
});

test('a shipment Mengantar books but cannot pay for is noted and left LUNAS', function () {
    ($this->mengantar)(['POST /order' => Http::response(['data' => [['ORDER_ID' => 'M124', '_id' => 'a1b2c3d4e5f6a1b2c3d4e5f6']]])]);
    $order = ($this->buat)(StatusOrder::Lunas, ['catatan_admin' => 'LUNAS 2026-09-28 10:00']);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Kirim, $order, $this->falah);

    expect($order->refresh()->status_order)->toBe(StatusOrder::Lunas)
        ->and($order->no_resi)->toBeNull()
        ->and($order->catatan_admin)->toContain('MENGANTAR ORDER M124 #a1b2c3d4e5f6a1b2c3d4e5f6 BELUM DIBAYAR')
        ->and(Notifikasi::query()->where('jenis', 'PENGIRIMAN')->sole()->detail)->toContain('BELUM DIBAYAR', 'saldo Mengantar kurang');
});

test('Mengantar refusing the booking says why, and the API key never reaches a notice', function () {
    ($this->mengantar)(['POST /order' => Http::response(['success' => false, 'message' => 'key rahasia-kunci tidak valid', 'errors' => [['path' => 'orders.0.weight', 'message' => 'terlalu berat']]], 400)]);
    $order = ($this->buat)(StatusOrder::Lunas);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Kirim, $order, $this->falah);

    $detail = Notifikasi::query()->where('jenis', 'PENGIRIMAN')->sole()->detail;

    expect($order->refresh()->status_order)->toBe(StatusOrder::Lunas)
        ->and($detail)->toContain('BELUM dibuat', 'kode 400', 'orders.0.weight: terlalu berat', 'tidak valid')
        ->not->toContain('rahasia-kunci');
});

test('a second booking for an order that already has one is refused', function () {
    ($this->mengantar)();
    $order = ($this->buat)(StatusOrder::Lunas, ['catatan_admin' => 'LUNAS 2026-09-28 10:00 | MENGANTAR ORDER M123 BELUM DIBAYAR 2026-09-28 10:05']);

    app(PerintahOrder::class)->jalankan(AksiPesanan::Kirim, $order, $this->falah);

    expect(Notifikasi::query()->where('jenis', 'PENGIRIMAN')->sole()->detail)->toContain('sudah pernah dibuat di Mengantar');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/order'));
});

test('BATAL KIRIM cancels at Mengantar first, then puts the order back to LUNAS', function () {
    ($this->mengantar)();
    $order = ($this->buat)(StatusOrder::Dikirim, [
        'no_resi' => 'JNE999', 'kurir_final' => 'JNE', 'dikirim_at' => now(),
        'catatan_admin' => 'LUNAS 2026-09-28 10:00 | DIKIRIM 2026-09-28 10:01 resi JNE999 (MENGANTAR ORDER M123 #a1b2c3d4e5f6a1b2c3d4e5f6, JNE)',
    ]);

    $this->actingAs($this->falah)->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'BATAL KIRIM'])->assertSessionHasNoErrors();

    $order->refresh();

    expect($order->status_order)->toBe(StatusOrder::Lunas)
        ->and($order->no_resi)->toBeNull()
        ->and($order->kurir_final)->toBeNull()
        ->and($order->catatan_admin)->toEndWith('| BATAL KIRIM M123 2026-09-28 13:42');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r['courier'] === 'JNE' && $r['ids'] === ['a1b2c3d4e5f6a1b2c3d4e5f6']);
});

test('BATAL KIRIM refused by Mengantar leaves the order as it was and shows the reason', function () {
    ($this->mengantar)(['DELETE /order' => Http::response(['deletedCount' => 0])]);
    $order = ($this->buat)(StatusOrder::Dikirim, ['no_resi' => 'JNE999', 'catatan_admin' => 'DIKIRIM 2026-09-28 10:01 resi JNE999 (MENGANTAR ORDER M123)']);

    $this->actingAs($this->falah)->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'BATAL KIRIM'])
        ->assertSessionHasErrors('aksi');

    expect($order->refresh()->status_order)->toBe(StatusOrder::Dikirim)
        ->and($order->no_resi)->toBe('JNE999');
});

// ---------------------------------------------------------------- resending the invoice, and the rates

test('"Kirim ulang ke customer" sends the PDF kept at ACC again', function () {
    $order = ($this->buat)();
    app(PerintahOrder::class)->jalankan(AksiPesanan::Acc, $order, $this->falah);
    $this->wa->terkirim = [];
    $this->app->bind(KirimUlangInvoice::class, LokalKirimUlangInvoice::class);

    $this->actingAs($this->falah)->post('/pesanan/ORD-260928-014/kirim-ulang-invoice')->assertSessionHasNoErrors();

    expect($this->wa->terkirim)->toHaveCount(1)
        ->and($this->wa->terkirim[0]['berkas'])->toBe('INV-2026-09-001.pdf')
        ->and($this->wa->terkirim[0]['teks'])->toBe("Halo Kak Rina Maharani 😊\n\nBerikut kami kirim ulang invoice INV/2026/09/001 untuk pesanan *ORD-260928-014* ya kak 🙏");
});

test('there is no PDF to send again before the order is ACC\'d', function () {
    $order = ($this->buat)(StatusOrder::Diacc, ['invoice_number' => 'INV/2026/09/007']);

    expect(fn () => app(LokalKirimUlangInvoice::class)->kirim($order, $this->falah))
        ->toThrow(PerintahGagal::class, 'PDF invoice belum dibuat');
});

test('the rates are cheapest first, with the special price and without what is hidden or unsupported', function () {
    ($this->mengantar)();

    $tarif = app(LokalCekOngkir::class)->cek('Dinoyo, Lowokwaru, Malang', 2.5);

    // SAPLite (Rp9.975 whatever the weight) is hidden, Lion is unsupported, SiCepat charges its special price
    expect(array_column($tarif, 'kurir'))->toBe(['SiCepat', 'JNE'])
        ->and(array_column($tarif, 'harga'))->toBe([30000, 38000])
        ->and($tarif[1]['estimasi'])->toBe('2-3 hari');
});

test('rates ask for the API key and the pickup address first', function () {
    ConfigToko::query()->where('kunci', 'MENGANTAR_API_KEY')->delete();
    Http::fake();

    expect(fn () => app(LokalCekOngkir::class)->cek('Malang', 1))->toThrow(PerintahGagal::class, 'API key atau alamat asal Mengantar belum diisi');
    Http::assertNothingSent();
});

test('a refused API key is said so, not shown as a server error', function () {
    ($this->mengantar)(['GET /address/search' => Http::response(['message' => 'Unauthorized'], 401)]);

    expect(fn () => app(LokalCekOngkir::class)->cek('Malang', 1))->toThrow(PerintahGagal::class, 'API key Mengantar ditolak');
});

test('without the pickup address id the origin is looked up by name', function () {
    ConfigToko::query()->where('kunci', 'MENGANTAR_PICKUP_ADDRESS_ID')->delete();
    ($this->mengantar)(['GET /address/search' => fn (Request $r) => Http::response(['data' => [
        ['_id' => 'asal1', 'SUBDISTRICT_NAME' => 'LAWANG', 'DISTRICT_NAME' => 'LAWANG', 'CITY_NAME' => 'MALANG', 'PROVINCE_NAME' => 'JAWA TIMUR'],
    ]])]);

    $tarif = app(LokalCekOngkir::class)->cek('Lawang, Malang', 1);

    expect(array_column($tarif, 'kurir'))->toBe(['SiCepat', 'JNE']);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/order/estimate') === false || $r['origin_id'] === 'asal1');
});

test('ACC does not crash when no working hours were ever written', function () {
    ConfigToko::query()->whereIn('kunci', ['JAM_PESAN_OTOMATIS', 'HARI_LIBUR_OTOMATIS'])->delete();
    $order = ($this->buat)();

    app(PerintahOrder::class)->jalankan(AksiPesanan::Acc, $order, $this->falah);

    // 07:00-21:00 is assumed; ACC at 13:42 with 6 working hours to pay ends at 19:42
    expect($order->refresh()->batas_bayar_at->format('Y-m-d H:i'))->toBe('2026-09-28 19:42');
});
