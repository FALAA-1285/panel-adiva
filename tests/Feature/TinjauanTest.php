<?php

use App\Enums\Peran;
use App\Models\ActivityLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // the demo freezes the clock on every request, so "91 days later" would never come
    config(['app.demo' => false]);
    $this->falah = User::factory()->falah()->create();
});

test('an access review is recorded with the accounts as they stood', function () {
    User::factory()->create(['name' => 'Dina', 'peran' => Peran::StafCs]);
    User::factory()->create(['name' => 'Rizky', 'nonaktif_at' => now()]);

    $this->actingAs($this->falah)->post('/tim/tinjau')->assertRedirect();

    $baris = ActivityLog::query()->where('aksi', 'Tinjau akses')->sole();
    expect($baris->pelaku)->toBe('Falah')
        ->and($baris->detail)->toBe('2 akun aktif ditinjau')
        ->and(collect($baris->data['akun'])->pluck('nama')->all())->toBe(['Falah', 'Dina']);
});

test('Tim & akses shows the last access review and when the next is due', function () {
    $this->actingAs($this->falah)->get('/tim')
        ->assertInertia(fn (Assert $page) => $page->where('tinjauan.terakhir', null)->where('tinjauan.jatuhTempo', true)->where('tinjauan.setiapHari', 90));

    $this->actingAs($this->falah)->post('/tim/tinjau');
    $this->actingAs($this->falah)->get('/tim')
        ->assertInertia(fn (Assert $page) => $page->where('tinjauan.oleh', 'Falah')->where('tinjauan.jatuhTempo', false));

    $this->travel(91)->days();
    // a password this old would send Falah to Ganti kata sandi first (WajibGantiSandi)
    $this->falah->forceFill(['sandi_diubah_at' => now()])->saveQuietly();
    $this->actingAs($this->falah)->get('/tim')
        ->assertInertia(fn (Assert $page) => $page->where('tinjauan.jatuhTempo', true));
});

test('a log review is recorded with the week\'s wrong passwords', function () {
    ActivityLog::create(['waktu' => now()->subDays(2), 'pelaku' => 'x@y.test', 'sumber' => 'PANEL', 'kategori' => 'LOGIN', 'aksi' => 'Gagal masuk']);
    ActivityLog::create(['waktu' => now()->subDays(9), 'pelaku' => 'x@y.test', 'sumber' => 'PANEL', 'kategori' => 'LOGIN', 'aksi' => 'Gagal masuk']);

    $this->actingAs($this->falah)->post('/aktivitas/tinjau')->assertRedirect();

    expect(ActivityLog::query()->where('aksi', 'Tinjau log')->sole()->detail)->toBe('Log 7 hari terakhir ditinjau · 1 gagal masuk');
    $this->actingAs($this->falah)->get('/aktivitas')
        ->assertInertia(fn (Assert $page) => $page->where('tinjauan.oleh', 'Falah')->where('tinjauan.setiapHari', 7));
});

test('only those who manage the team and the log can record their reviews', function () {
    $cs = User::factory()->create(['peran' => Peran::StafCs]);

    $this->actingAs($cs)->post('/tim/tinjau')->assertForbidden();
    $this->actingAs($cs)->post('/aktivitas/tinjau')->assertForbidden();
    expect(ActivityLog::query()->count())->toBe(0);
});
