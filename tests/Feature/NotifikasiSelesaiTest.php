<?php

use App\Bot\LapisanData;
use App\Enums\JenisNotifikasi;
use App\Enums\StatusOrder;
use App\Enums\SumberAktivitas;
use App\Models\ActivityLog;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\User;

test('an order leaving Menunggu ACC closes its "order baru" notification', function () {
    $order = LogOrder::factory()->status(StatusOrder::MenungguKonfirmasi)->create();
    $baru = Notifikasi::factory()->jenis(JenisNotifikasi::OrderBaru)->belumDibaca()->create(['log_order_id' => $order->id]);
    $lain = Notifikasi::factory()->jenis(JenisNotifikasi::OrderBaru)->belumDibaca()->create();

    $order->update(['status_order' => StatusOrder::Diacc]);

    expect($baru->fresh())->selesai_at->not->toBeNull()->dibaca_at->not->toBeNull()
        ->and($lain->fresh())->selesai_at->toBeNull()->dibaca_at->toBeNull();
});

test('other changes to an order leave its notifications open', function () {
    $order = LogOrder::factory()->status(StatusOrder::MenungguKonfirmasi)->create();
    $baru = Notifikasi::factory()->jenis(JenisNotifikasi::OrderBaru)->belumDibaca()->create(['log_order_id' => $order->id]);

    $order->update(['catatan_admin' => 'cek alamat']);

    expect($baru->fresh()->selesai_at)->toBeNull();
});

test('a paid order closes its "bukti masuk" notification', function () {
    $order = LogOrder::factory()->status(StatusOrder::Diacc)->create();
    $bukti = Notifikasi::factory()->jenis(JenisNotifikasi::BuktiMasuk)->belumDibaca()->create(['log_order_id' => $order->id]);

    $order->update(['status_order' => StatusOrder::Lunas]);

    expect($bukti->fresh()->selesai_at)->not->toBeNull();
});

test('clearing "butuh admin" closes the customer\'s notices', function () {
    $customer = CrmCustomer::factory()->create(['butuh_admin' => true]);
    $notif = Notifikasi::factory()->jenis(JenisNotifikasi::ButuhAdmin)->belumDibaca()->create(['crm_customer_id' => $customer->id]);

    $customer->update(['butuh_admin' => false]);

    expect($notif->fresh())->selesai_at->not->toBeNull()->dibaca_at->not->toBeNull();
});

test('ending a pause closes the customer\'s pause notices', function () {
    $customer = CrmCustomer::factory()->create();
    $jeda = JedaBot::query()->create([
        'crm_customer_id' => $customer->id,
        'chat_id' => $customer->chat_id,
        'sejak' => now()->subHour(),
        'oleh' => 'Falah',
    ]);
    $notif = Notifikasi::factory()->jenis(JenisNotifikasi::JedaChat)->belumDibaca()->create(['crm_customer_id' => $customer->id]);

    $jeda->update(['selesai_at' => now(), 'diakhiri_oleh' => 'Falah']);

    expect($notif->fresh()->selesai_at)->not->toBeNull();
});

test('a status the bot changes outside the panel is logged as Sistem and closes the notice', function () {
    $order = LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-BB']);
    $bukti = Notifikasi::factory()->jenis(JenisNotifikasi::BuktiMasuk)->belumDibaca()->create(['log_order_id' => $order->id]);

    // the Batas Bayar workflow closes an unpaid order through the n8n adaptor
    LapisanData::ubahOrder(['order_id' => 'ORD-BB', 'status_order' => 'BATAL OTOMATIS']);

    expect($bukti->fresh()->selesai_at)->not->toBeNull()
        ->and(ActivityLog::query()->sole())
        ->pelaku->toBe('Sistem')
        ->sumber->toBe(SumberAktivitas::Sistem)
        ->detail->toBe('ORD-BB · DIACC → BATAL OTOMATIS');
});

test('a command from the panel writes its own line, not a Sistem one', function () {
    $order = LogOrder::factory()->status(StatusOrder::MenungguKonfirmasi)->create(['order_id' => 'ORD-PN']);

    $this->actingAs(User::factory()->falah()->create())
        ->post('/pesanan/ORD-PN/aksi', ['aksi' => 'TOLAK', 'catatan' => 'stok habis'])
        ->assertSessionHasNoErrors();

    expect($order->refresh()->status_order)->toBe(StatusOrder::Ditolak)
        ->and(ActivityLog::query()->pluck('pelaku')->all())->toBe(['Falah']);
});
