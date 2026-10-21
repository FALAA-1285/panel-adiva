<?php

use App\Enums\StatusOrder;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('every panel table exists with its key columns', function (string $tabel, array $kolom) {
    expect(Schema::hasTable($tabel))->toBeTrue()
        ->and(Schema::hasColumns($tabel, $kolom))->toBeTrue();
})->with([
    ['config_toko', ['kunci', 'nilai', 'keterangan']],
    ['produk', ['jenis_produk', 'kategori_produk', 'informasi_harga', 'harga_bertingkat']],
    ['paket_reseller', ['kode_paket', 'nama_paket', 'isi_detail', 'harga', 'status_data']],
    ['gambar_produk', ['produk_id', 'title', 'tags', 'caption', 'aktif']],
    ['kebijakan', ['kode', 'topik', 'jawaban_resmi', 'kata_pemicu']],
    ['harga_karyawan', ['varian_produk', 'singkatan', 'harga_karyawan', 'batas']],
    ['karyawan', ['nama', 'nomor_wa', 'bagian', 'status']],
    ['template_followup', ['kode', 'pesan', 'status_aktif', 'tahap']],
    ['crm_customer', ['chat_id', 'tahap_funnel', 'skor_prospek', 'sesi_wa', 'mem_gen']],
    ['log_order', ['order_id', 'status_order', 'crm_customer_id', 'bukti_masuk_at', 'diacc_at']],
    ['order_items', ['log_order_id', 'nama_produk', 'qty', 'harga_satuan', 'subtotal']],
    ['jeda_bot', ['chat_id', 'sejak', 'sampai', 'sudah_diberi_tahu', 'selesai_at']],
    ['arsip_data', ['jenis', 'kunci', 'data', 'diarsipkan']],
    ['users', ['nomor_wa', 'cabang', 'peran', 'terakhir_masuk_at']],
    ['pesan', ['crm_customer_id', 'waktu', 'pengirim', 'jalur', 'meta']],
    ['notifikasi', ['waktu', 'jenis', 'kategori', 'penerima', 'dibaca_at']],
    ['activity_log', ['waktu', 'pelaku', 'sumber', 'kategori', 'subjek_type', 'data']],
    ['broadcast', ['nama', 'filter', 'status', 'jumlah_target', 'jadwal_at']],
    ['broadcast_penerima', ['broadcast_id', 'chat_id', 'status', 'balasan', 'minta_stop']],
    ['prompt_versi', ['versi', 'ringkasan', 'bagian', 'status']],
    ['workflow', ['kode', 'nama', 'jadwal', 'status', 'pengaturan']],
    ['workflow_event', ['workflow_id', 'waktu', 'jenis', 'status', 'judul']],
    ['riwayat', ['subjek_type', 'subjek_id', 'waktu', 'teks', 'nada']],
]);

test('every datetime column is timestamptz', function () {
    $tanpaZona = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('data_type', 'timestamp without time zone')
        ->pluck('column_name', 'table_name');

    expect($tanpaZona)->toBeEmpty();
});

test('datetimes round-trip as the same instant in WIB', function () {
    $order = LogOrder::create([
        'order_id' => 'ORD-260928-014',
        'tanggal' => '2026-09-28 13:06:00',
    ]);

    $utc = DB::scalar("select to_char(tanggal at time zone 'UTC', 'YYYY-MM-DD HH24:MI') from log_order");

    expect($utc)->toBe('2026-09-28 06:06')
        ->and($order->fresh()->tanggal->format('Y-m-d H:i T'))->toBe('2026-09-28 13:06 WIB');
});

test('an order keeps its customer, ordered items, status enum and timeline', function () {
    $customer = CrmCustomer::factory()->create();
    $order = LogOrder::create([
        'order_id' => 'ORD-260928-014',
        'crm_customer_id' => $customer->id,
        'tanggal' => now(),
        'total_produk' => 1245000,
        'ongkir' => 38000,
    ]);
    $order->items()->createMany([
        ['urutan' => 2, 'nama_produk' => 'Basreng Pedas Daun Jeruk', 'qty' => 30, 'harga_satuan' => 19500, 'subtotal' => 585000],
        ['urutan' => 1, 'nama_produk' => 'Molreng Reguler Bawang Original', 'qty' => 40, 'harga_satuan' => 16500, 'subtotal' => 660000],
    ]);
    $order->riwayat()->create(['waktu' => now(), 'teks' => 'Adiva mencatat order']);

    $order = $order->fresh();

    expect($order->status_order)->toBe(StatusOrder::MenungguKonfirmasi)
        ->and($order->status_order->label())->toBe('Menunggu ACC')
        ->and($order->customer->is($customer))->toBeTrue()
        ->and($order->items->pluck('nama_produk')->all())->toBe(['Molreng Reguler Bawang Original', 'Basreng Pedas Daun Jeruk'])
        ->and($customer->orders()->count())->toBe(1)
        ->and($order->riwayat()->sole()->subjek_type)->toBe('log_order');
});

test('deleting an order removes its items', function () {
    $order = LogOrder::create(['order_id' => 'ORD-260928-014', 'tanggal' => now()]);
    $order->items()->create(['nama_produk' => 'Kripca 1,8 kg Original', 'qty' => 1, 'harga_satuan' => 90000, 'subtotal' => 90000]);

    $order->delete();

    expect(DB::table('order_items')->count())->toBe(0);
});

test('a pause stays as history after LANJUT', function () {
    $customer = CrmCustomer::factory()->create();
    $jeda = JedaBot::create(['crm_customer_id' => $customer->id, 'chat_id' => $customer->chat_id, 'sejak' => now()]);

    expect(JedaBot::aktif()->count())->toBe(1);

    $jeda->update(['selesai_at' => now(), 'catatan_selesai' => 'Bot diaktifkan lagi dari panel']);

    expect(JedaBot::aktif()->count())->toBe(0)
        ->and(JedaBot::selesai()->count())->toBe(1);
});

test('all migrations roll back and run again', function () {
    try {
        Artisan::call('migrate:reset');

        expect(Schema::hasTable('log_order'))->toBeFalse();
    } finally {
        Artisan::call('migrate');
    }

    expect(Schema::hasTable('log_order'))->toBeTrue();
});
