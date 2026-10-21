<?php

use App\Enums\KategoriAktivitas;
use App\Enums\Peran;
use App\Enums\StatusOrder;
use App\Enums\TipeCustomer;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    ConfigToko::create(['kunci' => 'DAFTAR_NOMOR_BOT', 'nilai' => 'bangbana-waha=Nomor Utama']);
    $this->toko = CrmCustomer::factory()->create([
        'nama' => 'Toko Berkah Jaya', 'nomor_wa' => '6285744100036', 'sesi_wa' => 'bangbana-waha',
        'tipe_customer' => TipeCustomer::Reseller, 'kota' => 'Blitar', 'tgl_interaksi_terakhir' => '2026-09-27 15:00',
    ]);
});

test('only staff who may record orders open the form', function () {
    $this->get('/pesanan/baru')->assertRedirect('/masuk');

    $pj = User::factory()->create(['peran' => Peran::PjKaryawan]);
    $this->actingAs($pj)->get('/pesanan/baru')->assertForbidden();
    $this->actingAs($pj)->post('/pesanan', [])->assertForbidden();
    $this->actingAs(User::factory()->create(['peran' => Peran::StafCs]))->get('/pesanan/baru')->assertOk();
});

test('the customer is looked up by name, as CATAT <nama> does', function () {
    // contains the word, but a name that starts with it comes first
    CrmCustomer::factory()->create(['nama' => 'Toko Berkah Abadi', 'tgl_interaksi_terakhir' => '2026-09-20 10:00']);
    CrmCustomer::factory()->create(['nama' => 'Warung Toko Berkah', 'tgl_interaksi_terakhir' => '2026-09-28 13:00']);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)
        ->get('/pesanan/baru?cari=Toko Berkah')
        ->assertInertia(fn (Assert $page) => $page
            ->component('pesanan/baru')
            ->where('customer', [
                'id' => $this->toko->id,
                'inisial' => 'TB',
                'nama' => 'Toko Berkah Jaya',
                'nomor' => '0857-4410-••36',
                'tipe' => 'Reseller',
                'kota' => 'Blitar',
                'nomorBot' => 'Nomor Utama',
                'orderBerjalan' => null,
            ]));

    $this->actingAs($falah)->get('/pesanan/baru?cari=T')->assertInertia(fn (Assert $page) => $page->where('customer', null));
    $this->actingAs($falah)->get('/pesanan/baru')->assertInertia(fn (Assert $page) => $page->where('customer', null));
});

test('a customer with an order still running shows it', function () {
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-260927-031', 'crm_customer_id' => $this->toko->id]);
    // finished orders do not count
    LogOrder::factory()->status(StatusOrder::Selesai)->create(['crm_customer_id' => $this->toko->id]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/pesanan/baru?cari=Toko Berkah')
        ->assertInertia(fn (Assert $page) => $page->where('customer.orderBerjalan', 'ORD-260927-031'));
});

test('a manual order waits for ACC and opens its page', function () {
    LogOrder::factory()->create(['order_id' => 'ORD-260928-014']);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)
        ->post('/pesanan', [
            'customer_id' => $this->toko->id,
            'item' => [
                ['nama' => 'Basreng Pedas Daun Jeruk', 'qty' => 100, 'harga' => 18500],
                ['nama' => 'Kripca 1,8 kg Original', 'qty' => 5, 'harga' => 90000],
            ],
            // Rp10.000 more than the items: the shipping typed into the total
            'total' => 2310000,
            'kurir' => 'SiCepat REG',
            'alamat' => 'Jl. Merdeka 21, Kepanjenkidul, Kota Blitar',
            'tambahan' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/pesanan/ORD-260928-015');

    $order = LogOrder::query()->where('order_id', 'ORD-260928-015')->sole();

    expect($order->only(['status_order', 'total_produk', 'ongkir', 'kurir_pilihan', 'alamat_lengkap', 'nama_penerima', 'metode_bayar', 'catatan_admin', 'dicatat_oleh_id', 'pesanan_tambahan']))->toBe([
        'status_order' => StatusOrder::MenungguKonfirmasi,
        'total_produk' => 2300000,
        'ongkir' => 10000,
        'kurir_pilihan' => 'SiCepat REG',
        'alamat_lengkap' => 'Jl. Merdeka 21, Kepanjenkidul, Kota Blitar',
        'nama_penerima' => 'Toko Berkah Jaya',
        'metode_bayar' => 'TRANSFER',
        'catatan_admin' => 'dicatat manual oleh admin',
        'dicatat_oleh_id' => $falah->id,
        'pesanan_tambahan' => false,
    ])
        ->and($order->tipe_customer)->toBe(TipeCustomer::Reseller)
        ->and($order->items->map->only(['nama_produk', 'qty', 'harga_satuan', 'subtotal'])->all())->toBe([
            ['nama_produk' => 'Basreng Pedas Daun Jeruk', 'qty' => 100, 'harga_satuan' => 18500, 'subtotal' => 1850000],
            ['nama_produk' => 'Kripca 1,8 kg Original', 'qty' => 5, 'harga_satuan' => 90000, 'subtotal' => 450000],
        ])
        ->and($order->riwayat()->sole()->teks)->toBe('Dicatat manual oleh Falah dari panel')
        ->and(ActivityLog::query()->sole()->only(['kategori', 'aksi', 'detail']))->toBe([
            'kategori' => KategoriAktivitas::Pesanan->value,
            'aksi' => 'Catat order manual',
            'detail' => 'ORD-260928-015 · Toko Berkah Jaya · Rp2.310.000',
        ]);
});

test('an incomplete order is not recorded', function () {
    $falah = User::factory()->falah()->create();
    $item = [['nama' => 'Basreng Pedas Daun Jeruk', 'qty' => 100, 'harga' => 18500]];

    $this->actingAs($falah)->post('/pesanan', ['item' => $item])
        ->assertSessionHasErrors(['customer_id' => 'Pilih customer dari CRM.']);
    $this->actingAs($falah)->post('/pesanan', ['customer_id' => $this->toko->id, 'item' => []])
        ->assertSessionHasErrors(['item' => 'Tambahkan minimal satu item.']);
    $this->actingAs($falah)->post('/pesanan', ['customer_id' => $this->toko->id, 'item' => $item, 'total' => 1000])
        ->assertSessionHasErrors(['total' => 'Total tidak boleh kurang dari jumlah item.']);

    expect(LogOrder::query()->count())->toBe(0);
});

test('a customer with an order still running needs "pesanan tambahan"', function () {
    LogOrder::factory()->create(['order_id' => 'ORD-260928-014', 'crm_customer_id' => $this->toko->id]);
    $falah = User::factory()->falah()->create();
    $isi = ['customer_id' => $this->toko->id, 'item' => [['nama' => 'Basreng Pedas Daun Jeruk', 'qty' => 10, 'harga' => 18500]]];

    $this->actingAs($falah)->post('/pesanan', $isi)
        ->assertSessionHasErrors(['tambahan' => 'Customer masih punya order berjalan (ORD-260928-014). Centang pesanan tambahan untuk tetap mencatat.']);

    $this->actingAs($falah)->post('/pesanan', [...$isi, 'tambahan' => true])->assertSessionHasNoErrors();

    expect(LogOrder::query()->where('order_id', 'ORD-260928-015')->value('pesanan_tambahan'))->toBeTrue();
});
