<?php

use App\Enums\Peran;
use App\Enums\StatusData;
use App\Enums\StatusOrder;
use App\Enums\TipeCustomer;
use App\Models\ConfigToko;
use App\Models\HargaKaryawan;
use App\Models\Karyawan;
use App\Models\LogOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to the login page', function () {
    $this->get('/katalog/karyawan')->assertRedirect('/masuk');
});

test('the summary counts this month\'s employee orders and deductions', function () {
    $yoga = '6289612000077';
    User::factory()->create(['name' => 'Bu Sarah', 'nomor_wa' => '6281200000000', 'peran' => Peran::PjKaryawan]);
    // the number shown is SARAH_WA, where the notifications go, not the account's own
    ConfigToko::create(['kunci' => 'SARAH_WA', 'nilai' => '6281298760646']);
    Karyawan::create(['nama' => 'Yoga', 'nomor_wa' => $yoga, 'status' => StatusData::Aktif->value]);
    Karyawan::create(['nama' => 'Siti', 'nomor_wa' => '6281377810025', 'status' => StatusData::Nonaktif->value]);

    $order = fn (string $tanggal, int $total, StatusOrder $status = StatusOrder::Karyawan) => LogOrder::factory()
        ->karyawan($yoga)->pada($tanggal)->status($status)->create(['total_produk' => $total]);
    $order('2026-09-28 10:31', 96000);
    $order('2026-09-20 10:00', 50000, StatusOrder::Selesai);
    $order('2026-09-21 10:00', 10000, StatusOrder::Dibatalkan);
    $order('2026-08-20 10:00', 48000, StatusOrder::Selesai);
    // a customer order in the same month does not count
    LogOrder::factory()->pada('2026-09-22 10:00')->status(StatusOrder::Selesai)
        ->create(['tipe_customer' => TipeCustomer::Retail, 'total_produk' => 90000]);

    $this->actingAs(User::factory()->create())
        ->get('/katalog/karyawan')
        ->assertInertia(fn (Assert $page) => $page
            ->component('katalog/karyawan')
            ->where('ringkasan.bulan', 'September')
            ->where('ringkasan.order', 2)
            ->where('ringkasan.berjalan', 1)
            ->where('ringkasan.potongGaji', 'Rp146.000')
            ->where('ringkasan.karyawan', 2)
            ->where('ringkasan.nonaktif', 1)
            ->where('ringkasan.rataRata', 'Rp73.000')
            ->where('pj', ['nama' => 'Bu Sarah', 'peran' => 'PJ karyawan', 'nomor' => '0812-••••-0646']));
});

test('the summary needs one query for the orders and one for the employees', function () {
    $yoga = '6289612000077';
    Karyawan::create(['nama' => 'Yoga', 'nomor_wa' => $yoga]);
    LogOrder::factory()->karyawan($yoga)->count(3)->create(['total_produk' => 30000]);

    DB::enableQueryLog();
    $this->actingAs(User::factory()->create())->get('/katalog/karyawan');

    $agregat = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $q) => str_starts_with($q, 'select count(') || str_starts_with($q, 'select sum('));
    // this month's employee orders: count, running and total in one go (the sidebar
    // also counts log_order, but for "Pesanan" and without tipe_customer)
    expect($agregat->filter(fn (string $q) => str_contains($q, 'from "log_order" where "tipe_customer"')))->toHaveCount(1)
        // the summary's employees plus the tab count shared by the Katalog pages
        ->and($agregat->filter(fn (string $q) => str_contains($q, 'from "karyawan"')))->toHaveCount(2);
});

test('the average per order is rounded to the hundred', function () {
    $yoga = '6289612000077';
    Karyawan::create(['nama' => 'Yoga', 'nomor_wa' => $yoga]);

    foreach ([30000, 30000, 40000] as $total) {
        LogOrder::factory()->karyawan($yoga)->pada('2026-09-10 10:00')->create(['total_produk' => $total]);
    }

    $this->actingAs(User::factory()->create())
        ->get('/katalog/karyawan')
        ->assertInertia(fn (Assert $page) => $page->where('ringkasan.rataRata', 'Rp33.300'));
});

test('each employee shows all their orders and this month\'s deduction', function () {
    $yoga = '6289612000077';
    Karyawan::create(['nama' => 'Yoga', 'nomor_wa' => $yoga, 'bagian' => 'Produksi']);
    Karyawan::create(['nama' => 'Siti', 'nomor_wa' => '6281377810025', 'bagian' => 'Admin', 'status' => StatusData::Nonaktif->value]);

    LogOrder::factory()->karyawan($yoga)->pada('2026-09-28 10:31')->create(['total_produk' => 96000]);
    LogOrder::factory()->karyawan($yoga)->pada('2026-08-20 10:00')->status(StatusOrder::Selesai)->create(['total_produk' => 48000]);
    LogOrder::factory()->karyawan($yoga)->pada('2026-09-21 10:00')->status(StatusOrder::Ditolak)->create(['total_produk' => 10000]);

    $this->actingAs(User::factory()->create())
        ->get('/katalog/karyawan')
        ->assertInertia(fn (Assert $page) => $page
            ->where('karyawan.0.nama', 'Yoga')
            ->where('karyawan.0.nomor', '0896-1200-••77')
            ->where('karyawan.0.order', 2)
            ->where('karyawan.0.potongGaji', 'Rp96.000')
            ->where('karyawan.0.aktif', true)
            ->where('karyawan.1.order', 0)
            ->where('karyawan.1.potongGaji', null)
            ->where('karyawan.1.aktif', false));
});

test('the latest four orders show their items and where they stand', function () {
    $rahma = '6281255300004';
    User::factory()->create(['name' => 'Bu Sarah', 'peran' => Peran::PjKaryawan]);
    Karyawan::create(['nama' => 'Rahma', 'nomor_wa' => $rahma]);

    $order = fn (string $tanggal) => LogOrder::factory()->karyawan($rahma)->pada($tanggal);
    $order('2026-09-01 10:00')->status(StatusOrder::Selesai)->create(['total_produk' => 1000]);
    $order('2026-09-25 13:10')->status(StatusOrder::Selesai)->create(['total_produk' => 72000]);
    $order('2026-09-26 08:45')->create(['total_produk' => 70000, 'siap_diambil_at' => '2026-09-27 09:00']);
    $order('2026-09-27 09:20')->create(['total_produk' => 92000])->items()->createMany([
        ['urutan' => 1, 'nama_produk' => 'Molreng', 'qty' => 2, 'harga_satuan' => 20000, 'subtotal' => 40000],
        ['urutan' => 2, 'nama_produk' => 'Basreng Pedas', 'qty' => 2, 'harga_satuan' => 26000, 'subtotal' => 52000],
    ]);
    $order('2026-09-24 10:00')->status(StatusOrder::BatalOtomatis)->create(['total_produk' => 1000]);

    $this->actingAs(User::factory()->create())
        ->get('/katalog/karyawan')
        ->assertInertia(fn (Assert $page) => $page
            ->has('order', 4)
            ->where('order.0.karyawan', 'Rahma')
            ->where('order.0.item', 'Molreng ×2, Basreng Pedas ×2')
            ->where('order.0.nilai', 'Rp92.000')
            ->where('order.0.status', ['label' => 'Diteruskan ke Bu Sarah', 'nada' => 'amber'])
            ->where('order.1.status', ['label' => 'Siap diambil', 'nada' => 'blue'])
            ->where('order.2.status', ['label' => 'Selesai', 'nada' => 'green'])
            ->where('order.3.nilai', 'Rp1.000'));
});

test('employee prices show the placeholder until they are filled in', function () {
    HargaKaryawan::create(['varian_produk' => 'Basreng Original', 'singkatan' => 'BSR-O', 'batas' => '4 pack / minggu']);
    HargaKaryawan::create(['varian_produk' => 'Kripca 1,8 kg', 'singkatan' => 'KRC', 'batas' => '2 bal / bulan', 'harga_karyawan' => 70000]);

    $this->actingAs(User::factory()->create())
        ->get('/katalog/karyawan')
        ->assertInertia(fn (Assert $page) => $page
            ->where('harga.0', ['id' => HargaKaryawan::query()->where('singkatan', 'BSR-O')->value('id'), 'varian' => 'Basreng Original', 'kode' => 'BSR-O', 'harga' => null, 'batas' => '4 pack / minggu', 'hargaAngka' => null])
            ->where('harga.1.harga', 'Rp70.000'));
});

test('Unduh rekap bulan ini lists this month\'s employee orders for payroll', function () {
    $yoga = '6289612000077';
    Karyawan::create(['nama' => 'Yoga', 'nomor_wa' => $yoga, 'bagian' => 'Produksi', 'status' => StatusData::Aktif->value]);
    LogOrder::factory()->karyawan($yoga)->pada('2026-09-28 10:31')->create(['order_id' => 'ORD-260928-011', 'total_produk' => 96000]);
    LogOrder::factory()->karyawan($yoga)->pada('2026-08-20 10:00')->create(['order_id' => 'ORD-260820-001', 'total_produk' => 48000]);
    LogOrder::factory()->pada('2026-09-22 10:00')->create(['order_id' => 'ORD-260922-001', 'tipe_customer' => TipeCustomer::Retail]);

    $csv = $this->actingAs(User::factory()->create())
        ->get('/katalog/karyawan/rekap')
        ->assertOk()
        ->assertDownload('rekap-karyawan-2026-09.csv')
        ->streamedContent();

    $baris = array_map('str_getcsv', explode("\n", trim(substr($csv, 3))));
    expect($baris)->toHaveCount(2)
        ->and($baris[0])->toBe(['Tanggal', 'Order', 'Karyawan', 'Bagian', 'Nomor WA', 'Item', 'Potong gaji', 'Status'])
        ->and(array_slice($baris[1], 0, 5))->toBe(['2026-09-28 10:31', 'ORD-260928-011', 'Yoga', 'Produksi', $yoga])
        ->and($baris[1][6])->toBe('96000');
});
