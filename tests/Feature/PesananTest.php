<?php

use App\Enums\KategoriAktivitas;
use App\Enums\Peran;
use App\Enums\StatusOrder;
use App\Enums\TipeCustomer;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\LogOrder;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    foreach (['BATAS_BAYAR_JAM' => '5', 'JAM_PESAN_OTOMATIS' => '07:00-21:00', 'HARI_LIBUR_OTOMATIS' => 'Minggu'] as $kunci => $nilai) {
        ConfigToko::create(['kunci' => $kunci, 'nilai' => $nilai]);
    }
});

test('guests are sent to the login page', function () {
    $this->get('/pesanan')->assertRedirect('/masuk');
});

test('Pesanan lists running orders, today\'s employee orders and finished orders of the last 7 days', function () {
    LogOrder::factory()->pada('2026-09-28 13:06')->create(['order_id' => 'ORD-ACC']);
    LogOrder::factory()->pada('2026-09-10 09:00')->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-LAMA']);
    LogOrder::factory()->pada('2026-09-21 09:00')->status(StatusOrder::Selesai)->create(['order_id' => 'ORD-SELESAI']);
    LogOrder::factory()->pada('2026-09-26 11:00')->status(StatusOrder::Dibatalkan)->create(['order_id' => 'ORD-BATAL']);
    LogOrder::factory()->karyawan('6289612000077')->pada('2026-09-28 10:31')->create(['order_id' => 'ORD-KARYAWAN']);
    // hidden: finished 8 days ago, and an employee order from yesterday (Program karyawan)
    LogOrder::factory()->pada('2026-09-20 23:00')->status(StatusOrder::Selesai)->create();
    LogOrder::factory()->karyawan('6281255300004')->pada('2026-09-27 09:20')->create();

    $this->actingAs(User::factory()->falah()->create())
        ->get('/pesanan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('pesanan')
            // running first (newest first), then SELESAI, then cancelled
            ->where('baris', fn ($baris) => collect($baris)->pluck('id')->all() === ['ORD-ACC', 'ORD-KARYAWAN', 'ORD-LAMA', 'ORD-SELESAI', 'ORD-BATAL'])
            ->where('tab', fn ($tab) => collect($tab)->pluck('n', 'kode')->all() === [
                'semua' => 5, 'menunggu-acc' => 1, 'menunggu-bayar' => 1, 'bukti-masuk' => 0, 'lunas' => 0,
                'dikirim' => 0, 'karyawan' => 1, 'selesai' => 1, 'batal' => 1,
            ])
            ->where('detail.id', 'ORD-ACC'));
});

test('a tab filters the list and opens its first order, unless one is chosen', function () {
    LogOrder::factory()->pada('2026-09-27 19:12')->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-BUKTI', 'bukti_masuk_at' => '2026-09-28 08:14']);
    LogOrder::factory()->pada('2026-09-27 16:02')->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-BAYAR']);
    LogOrder::factory()->pada('2026-09-28 13:06')->create(['order_id' => 'ORD-ACC']);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)
        ->get('/pesanan?tab=bukti-masuk')
        ->assertInertia(fn (Assert $page) => $page
            ->where('aktifTab', 'bukti-masuk')
            ->where('baris', fn ($baris) => collect($baris)->pluck('id')->all() === ['ORD-BUKTI'])
            ->where('baris.0.bukti', true)
            ->where('detail.id', 'ORD-BUKTI'));

    $this->actingAs($falah)
        ->get('/pesanan?tab=menunggu-bayar&order=ORD-ACC')
        ->assertInertia(fn (Assert $page) => $page
            ->where('baris', fn ($baris) => collect($baris)->pluck('id')->all() === ['ORD-BUKTI', 'ORD-BAYAR'])
            ->where('detail.id', 'ORD-ACC'));
});

test('the open order shows its items, history and which commands apply', function () {
    $order = LogOrder::factory()->pada('2026-09-28 13:06')->create([
        'order_id' => 'ORD-260928-014', 'nama_penerima' => 'Rina Maharani', 'nomor_wa' => '6281233450081', 'tipe_customer' => TipeCustomer::Reseller,
        'metode_bayar' => 'TRANSFER', 'kurir_pilihan' => 'JNE REG', 'total_produk' => 1245000, 'ongkir' => 38000, 'berat_kg' => 1.6,
    ]);
    $order->items()->create(['urutan' => 1, 'nama_produk' => 'Molreng Reguler Bawang Original', 'qty' => 40, 'harga_satuan' => 16500, 'subtotal' => 660000]);
    $order->riwayat()->create(['waktu' => '2026-09-28 13:06', 'teks' => 'Dicatat Adiva dari chat']);
    $order->riwayat()->create(['waktu' => '2026-09-25 10:00', 'teks' => 'lama']);
    $order->riwayat()->create(['waktu' => '2026-09-28 13:02', 'jenis' => 'PERCAKAPAN', 'teks' => 'Chat pertama masuk']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/pesanan')
        ->assertInertia(fn (Assert $page) => $page
            ->where('baris.0.ringkas', 'Molreng Reguler Bawang Original ×40 · JNE REG')
            ->where('baris.0.total', 'Rp1.283.000')
            ->where('baris.0.bayar', 'Transfer')
            ->where('detail.item.0', ['nama' => 'Molreng Reguler Bawang Original', 'qty' => 40, 'subtotal' => 'Rp660.000'])
            ->where('detail.total', 'Rp1.283.000')
            ->where('detail.hp', '0812-3345-••81')
            ->where('detail.berat', '1,6 kg')
            ->where('detail.invoice', '—')
            ->where('detail.riwayat', [['waktu' => '25 Sep', 'teks' => 'lama'], ['waktu' => '13:06', 'teks' => 'Dicatat Adiva dari chat']])
            ->where('detail.langkah.0', ['label' => 'Menunggu ACC', 'selesai' => true, 'kini' => true, 'mati' => false, 'waktu' => '28 Sep 13:06'])
            // a step that has not happened yet carries no time
            ->where('detail.langkah.1', ['label' => 'Invoice', 'selesai' => false, 'kini' => false, 'mati' => false, 'waktu' => null])
            ->where('detail.aksi.0', ['kode' => 'ACC', 'boleh' => true, 'bolehDenganOngkir' => false, 'judul' => 'ACC → invoice PDF dibuat & dikirim', 'gaya' => 'utama'])
            ->where('detail.aksi.2.judul', 'SIAP hanya untuk pesanan ambil sendiri')
            ->where('detail.aksi.3.judul', 'KIRIM tidak berlaku untuk status MENUNGGU_KONFIRMASI'));
});

test('an order waiting for payment shows the working hours left', function () {
    LogOrder::factory()->pada('2026-09-27 16:02')->status(StatusOrder::Diacc)->create(['batas_bayar_at' => '2026-09-28 15:42']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/pesanan')
        ->assertInertia(fn (Assert $page) => $page->where('detail.riwayat', [
            ['waktu' => '—', 'teks' => 'Batas bayar 5 jam kerja (BATAS_BAYAR_JAM) · sisa 2 jam kerja'],
        ]));
});

test('ACC creates the next invoice, the payment deadline, a history line and a log entry', function () {
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['invoice_number' => 'INV/2026/09/138']);
    $order = LogOrder::factory()->create(['order_id' => 'ORD-260928-014', 'metode_bayar' => 'TRANSFER']);

    $this->actingAs(User::factory()->falah()->create())
        ->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'ACC'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $order->refresh();

    expect($order->status_order)->toBe(StatusOrder::Diacc)
        ->and($order->invoice_number)->toBe('INV/2026/09/139')
        ->and($order->diacc_at?->format('Y-m-d H:i'))->toBe('2026-09-28 13:42')
        // 5 working hours later, the same evening
        ->and($order->batas_bayar_at?->format('Y-m-d H:i'))->toBe('2026-09-28 18:42')
        ->and($order->riwayat()->sole()->teks)->toBe('ACC dari panel · invoice PDF dibuat & dikirim')
        ->and(ActivityLog::query()->sole()->only(['kategori', 'aksi', 'detail']))->toBe([
            'kategori' => KategoriAktivitas::Pesanan->value,
            'aksi' => 'ACC order',
            'detail' => 'ORD-260928-014 · invoice INV/2026/09/139 terkirim',
        ])
        ->and(ConfigToko::query()->where('kunci', 'INVOICE_TERAKHIR')->value('nilai'))->toBe('INV/2026/09/139');
});

test('the next invoice follows INVOICE_TERAKHIR from Pengaturan', function () {
    ConfigToko::create(['kunci' => 'INVOICE_TERAKHIR', 'nilai' => 'INV/2026/09/141']);
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['invoice_number' => 'INV/2026/09/138']);
    $order = LogOrder::factory()->create(['order_id' => 'ORD-260928-014', 'metode_bayar' => 'BAYAR DI TEMPAT (PICKUP)', 'ambil_sendiri' => true]);

    $this->actingAs(User::factory()->falah()->create())->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'ACC']);

    expect($order->refresh()->invoice_number)->toBe('INV/2026/09/142')
        // paid at pickup: no transfer deadline
        ->and($order->batas_bayar_at)->toBeNull()
        ->and(ConfigToko::query()->where('kunci', 'INVOICE_TERAKHIR')->value('nilai'))->toBe('INV/2026/09/142');
});

test('a command that does not apply is refused', function () {
    $falah = User::factory()->falah()->create();
    $diacc = LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-DIACC']);
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['order_id' => 'ORD-AMBIL', 'ambil_sendiri' => true]);
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['order_id' => 'ORD-KIRIM']);
    LogOrder::factory()->create(['order_id' => 'ORD-BARU']);

    // ACC again only with a new shipping cost (revisi ongkir)
    $this->actingAs($falah)->post('/pesanan/ORD-DIACC/aksi', ['aksi' => 'ACC'])
        ->assertSessionHasErrors(['aksi' => 'Order sudah di-ACC · tulis ongkir baru di catatan untuk revisi ongkir']);
    $this->actingAs($falah)->post('/pesanan/ORD-BARU/aksi', ['aksi' => 'ACC', 'catatan' => 'besok saja'])
        ->assertSessionHasErrors(['aksi' => 'Tulis ongkir dalam rupiah, mis. 25000 atau gratis']);
    $this->actingAs($falah)->post('/pesanan/ORD-AMBIL/aksi', ['aksi' => 'KIRIM', 'catatan' => 'CGK1'])
        ->assertSessionHasErrors(['aksi' => 'Pesanan ambil sendiri tidak dikirim kurir']);
    $this->actingAs($falah)->post('/pesanan/ORD-KIRIM/aksi', ['aksi' => 'MAJU'])
        ->assertSessionHasErrors('aksi');

    expect($diacc->refresh()->status_order)->toBe(StatusOrder::Diacc)
        ->and(ActivityLog::query()->count())->toBe(0);

    $this->actingAs($falah)->post('/pesanan/ORD-KIRIM/aksi', ['aksi' => 'KIRIM', 'catatan' => ' CGK0029104455 '])->assertSessionHasNoErrors();

    expect(LogOrder::query()->where('order_id', 'ORD-KIRIM')->sole()->only(['status_order', 'no_resi']))->toBe([
        'status_order' => StatusOrder::Dikirim, 'no_resi' => 'CGK0029104455',
    ])->and(ActivityLog::query()->sole()->detail)->toBe('ORD-KIRIM · resi CGK0029104455');
});

test('TOLAK keeps the reason in the history and the log', function () {
    $order = LogOrder::factory()->create(['order_id' => 'ORD-TOLAK']);

    $this->actingAs(User::factory()->falah()->create())
        ->post('/pesanan/ORD-TOLAK/aksi', ['aksi' => 'TOLAK', 'catatan' => 'stok habis']);

    expect($order->refresh()->status_order)->toBe(StatusOrder::Ditolak)
        ->and($order->riwayat()->sole()->teks)->toBe('TOLAK dari panel · customer dikabari · stok habis')
        ->and(ActivityLog::query()->sole()->detail)->toBe('ORD-TOLAK · customer dikabari · stok habis');
});

test('each role may run only its own commands', function () {
    LogOrder::factory()->create(['order_id' => 'ORD-CUSTOMER']);
    LogOrder::factory()->karyawan('6289612000077')->create(['order_id' => 'ORD-KARYAWAN']);
    $pj = User::factory()->create(['peran' => Peran::PjKaryawan]);

    $this->actingAs($pj)->post('/pesanan/ORD-CUSTOMER/aksi', ['aksi' => 'ACC'])
        ->assertSessionHasErrors(['aksi' => 'ACC hanya untuk Supervisor dan Staf CS']);
    $this->actingAs($pj)->post('/pesanan/ORD-KARYAWAN/aksi', ['aksi' => 'LUNAS'])->assertSessionHasNoErrors();
    $this->actingAs(User::factory()->create(['peran' => Peran::StafCs]))
        ->post('/pesanan/ORD-CUSTOMER/aksi', ['aksi' => 'ACC'])
        ->assertSessionHasNoErrors();

    expect(LogOrder::query()->orderBy('order_id')->pluck('status_order', 'order_id')->all())->toBe([
        'ORD-CUSTOMER' => StatusOrder::Diacc, 'ORD-KARYAWAN' => StatusOrder::Lunas,
    ]);
});

test('the open tab is exported as CSV', function () {
    LogOrder::factory()->pada('2026-09-28 13:06')->create(['order_id' => 'ORD-ACC', 'nama_penerima' => 'Rina Maharani', 'total_produk' => 1245000, 'ongkir' => 38000]);
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['order_id' => 'ORD-LUNAS']);

    $csv = $this->actingAs(User::factory()->falah()->create())
        ->get('/pesanan/ekspor?tab=menunggu-acc')
        ->assertOk()
        ->assertDownload('pesanan-menunggu-acc-2026-09-28.csv')
        ->streamedContent();

    expect($csv)->toContain('Order,Tanggal,Customer,Tipe,Item,Total,"Metode bayar",Kurir,Status')
        ->toContain('ORD-ACC,"2026-09-28 13:06","Rina Maharani",,,1283000,,,"Menunggu ACC"')
        ->not->toContain('ORD-LUNAS');
});
