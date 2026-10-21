<?php

use App\Enums\JenisNotifikasi;
use App\Enums\Peran;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to the login page', function () {
    $this->get('/notifikasi')->assertRedirect('/masuk');
});

test('notifications are grouped per day with their button', function () {
    User::factory()->create(['name' => 'Bu Sarah', 'peran' => Peran::PjKaryawan]);
    Notifikasi::factory()->jenis(JenisNotifikasi::OrderBaru)->pada('2026-09-28 13:06')->belumDibaca()
        ->create(['detail' => 'Rina Maharani · ORD-260928-014']);
    Notifikasi::factory()->jenis(JenisNotifikasi::PengingatJeda)->pada('2026-09-28 09:00')->create();
    Notifikasi::factory()->jenis(JenisNotifikasi::LaporanHarian)->pada('2026-09-27 20:00')->create();
    Notifikasi::factory()->jenis(JenisNotifikasi::OrderDibatalkan)->pada('2026-09-25 08:15')->create();

    $this->actingAs(User::factory()->create())
        ->get('/notifikasi')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifikasi')
            ->where('pj', 'Bu Sarah')
            ->has('hari', 3)
            ->where('hari.0.judul', 'Hari ini')
            ->where('hari.0.notifikasi.0', fn ($n) => $n['jam'] === '13:06' && $n['dibaca'] === false && $n['aksi'] === 'Tinjau' && $n['detail'] === 'Rina Maharani · ORD-260928-014')
            ->where('hari.0.notifikasi.1.aksi', null)
            ->where('hari.1.judul', 'Kemarin · Minggu')
            ->where('hari.1.notifikasi.0.aksi', 'Ringkasan')
            ->where('hari.2.judul', 'Jumat, 25 Sep'));
});

test('a type the panel does not know yet is listed without a button', function () {
    Notifikasi::factory()->create(['jenis' => 'JENIS_BARU_DARI_N8N']);

    $this->actingAs(User::factory()->create())
        ->get('/notifikasi')
        ->assertInertia(fn (Assert $page) => $page->where('hari.0.notifikasi.0.aksi', null));
});

test('tabs count and show their own notifications from the last seven days, plus anything unread', function () {
    Notifikasi::factory()->jenis(JenisNotifikasi::JedaChat)->pada('2026-09-28 13:30')->belumDibaca()->create();
    Notifikasi::factory()->jenis(JenisNotifikasi::OrderBaru)->pada('2026-09-28 13:06')->belumDibaca()->create();
    Notifikasi::factory()->jenis(JenisNotifikasi::NomorPutus)->pada('2026-09-28 11:52')->create();
    Notifikasi::factory()->jenis(JenisNotifikasi::OrderDibatalkan)->pada('2026-09-20 10:00')->create();
    Notifikasi::factory()->jenis(JenisNotifikasi::ButuhAdmin)->pada('2026-09-10 10:00')->belumDibaca()->create();

    $jumlah = ['semua' => 4, 'belum' => 3, 'order' => 1, 'butuh-admin' => 2, 'sistem' => 1];

    $this->actingAs(User::factory()->create())
        ->get('/notifikasi?tab=butuh-admin')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'butuh-admin')
            ->where('jumlah', fn ($tab) => collect($tab)->pluck('n', 'kode')->all() === $jumlah)
            ->where('hari.0.notifikasi.0.jam', '13:30')
            ->where('hari.1.notifikasi.0.jam', '10:00')
            ->has('hari', 2));
});

test('the tab numbers come from a single query', function () {
    Notifikasi::factory()->count(3)->create();
    $user = User::factory()->create();

    DB::enableQueryLog();
    $this->actingAs($user)->get('/notifikasi');

    $hitung = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'from "notifikasi"') && str_contains($q['query'], 'count('));
    // one for the tabs, one for the bell in the shared Topbar data
    expect($hitung)->toHaveCount(2);
});

test('an unknown tab falls back to Semua', function () {
    $this->actingAs(User::factory()->create())
        ->get('/notifikasi?tab=apa')
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'semua'));
});

test('a button marks the notification read and opens its page', function () {
    $order = LogOrder::create(['order_id' => 'ORD-260928-014', 'tanggal' => '2026-09-28 13:06']);
    $baru = Notifikasi::factory()->jenis(JenisNotifikasi::OrderBaru)->belumDibaca()->create(['log_order_id' => $order->id]);
    $karyawan = Notifikasi::factory()->jenis(JenisNotifikasi::OrderKaryawan)->belumDibaca()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get("/notifikasi/{$baru->id}/buka")->assertRedirect('/pesanan/ORD-260928-014');
    $this->actingAs($user)->get("/notifikasi/{$karyawan->id}/buka")->assertRedirect('/katalog/karyawan');

    expect($baru->refresh()->dibaca_at)->not->toBeNull()
        ->and($karyawan->refresh()->dibaca_at)->not->toBeNull();
});

test('a notification without a button cannot be opened and stays unread', function () {
    $arsip = Notifikasi::factory()->jenis(JenisNotifikasi::Arsip)->belumDibaca()->create();

    $this->actingAs(User::factory()->create())
        ->get("/notifikasi/{$arsip->id}/buka")
        ->assertNotFound();

    expect($arsip->refresh()->dibaca_at)->toBeNull();
});

test('Tandai semua dibaca clears the bell for the whole team', function () {
    Notifikasi::factory()->count(2)->belumDibaca()->create();

    $this->actingAs(User::factory()->create())->post('/notifikasi/baca-semua')->assertRedirect();

    expect(Notifikasi::query()->belumDibaca()->count())->toBe(0);
    $this->actingAs(User::factory()->create())
        ->get('/notifikasi')
        ->assertInertia(fn (Assert $page) => $page->where('panel.hitungan.notifikasi', 0));
});
