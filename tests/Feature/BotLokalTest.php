<?php

use App\Bot\KirimUlangInvoice;
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
use Illuminate\Support\Facades\DB;
use Tests\Support\PengirimRekam;

/*
 * BOT_DRIVER=lokal: the panel runs the order commands itself (docs/kontrak-n8n.md §8b).
 * The clock is frozen to Senin, 28 Sep 2026 13:42 WIB by demo mode.
 */

beforeEach(function () {
    foreach ([
        'REKENING' => 'BCA 123 a.n. Bang Bana', 'SESI_UTAMA' => 'bangbana-waha', 'BATAS_BAYAR_JAM' => '6',
        'JAM_PESAN_OTOMATIS' => '07:00-21:00', 'HARI_LIBUR_OTOMATIS' => 'Minggu',
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
});

// ---------------------------------------------------------------- who does the work

test('BOT_DRIVER=lokal gives the panel what it has taken over, and leaves the rest with n8n', function () {
    config(['app.demo' => false, 'services.bot.driver' => 'lokal']);
    $this->app->offsetUnset(PerintahOrder::class);
    (new AppServiceProvider($this->app))->register();

    expect(app(PerintahOrder::class))->toBeInstanceOf(LokalPerintahOrder::class)
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

// ---------------------------------------------------------------- shipping: expedition parcels are ONGKIR COD

test('KIRIM of an expedition parcel needs its waybill; the customer hears the shipping is COD', function () {
    $order = ($this->buat)(StatusOrder::Lunas, ['metode_bayar' => 'TRANSFER (ONGKIR COD)', 'kurir_pilihan' => 'EKSPEDISI (ONGKIR COD) - JNE', 'ongkir' => 25000]);

    $this->actingAs($this->falah)->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'KIRIM'])
        ->assertSessionHasErrors(['aksi' => 'Nomor resinya belum disertakan. Contoh: JNE1234567890']);
    expect($order->refresh()->status_order)->toBe(StatusOrder::Lunas);

    $this->actingAs($this->falah)->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'KIRIM', 'catatan' => 'JNE999'])->assertSessionHasNoErrors();

    expect($order->refresh()->status_order)->toBe(StatusOrder::Dikirim)
        ->and($order->no_resi)->toBe('JNE999')
        ->and(Pesan::query()->latest('id')->first()->teks)->toContain('Nomor resi: *JNE999*', 'Ongkir dibayar *COD* ke kurir saat paket sampai (Rp25.000) ya kak.');
});

// ---------------------------------------------------------------- resending the invoice

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

test('ACC does not crash when no working hours were ever written', function () {
    ConfigToko::query()->whereIn('kunci', ['JAM_PESAN_OTOMATIS', 'HARI_LIBUR_OTOMATIS'])->delete();
    $order = ($this->buat)();

    app(PerintahOrder::class)->jalankan(AksiPesanan::Acc, $order, $this->falah);

    // 07:00-21:00 is assumed; ACC at 13:42 with 6 working hours to pay ends at 19:42
    expect($order->refresh()->batas_bayar_at->format('Y-m-d H:i'))->toBe('2026-09-28 19:42');
});
