<?php

use App\Enums\JenisNotifikasi;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\Notifikasi;
use App\Models\User;
use App\Support\KabarBot;
use App\Support\KabarWa;
use Tests\Support\UserAgent;

beforeEach(function () {
    // the demo's frozen clock would never let a lock run out; the new-device code has its own tests
    config(['app.demo' => false]);
    ConfigToko::create(['kunci' => 'PANEL_KODE_PERANGKAT_BARU', 'nilai' => '0']);
    $this->staf = User::factory()->falah()->create(['password' => 'Benar#1234']);
    $this->salah = fn (string $masukan = 'falah@bangbana.id') => $this
        ->withHeader('User-Agent', UserAgent::WINDOWS)
        ->post('/masuk', ['email' => $masukan, 'password' => 'Salah#1234']);
});

test('a wrong password is written to Log aktivitas, without the password', function () {
    ($this->salah)()->assertSessionHasErrors('email');

    $baris = ActivityLog::query()->sole();
    expect($baris->only(['user_id', 'pelaku', 'aksi']))->toBe(['user_id' => $this->staf->id, 'pelaku' => 'Falah', 'aksi' => 'Gagal masuk'])
        ->and($baris->detail)->toContain('IP 127.0.0.1')
        ->and(json_encode($baris->data))->not->toContain('Salah#1234');
});

test('a login nobody owns is logged as typed', function () {
    ($this->salah)('orang@asing.test');

    expect(ActivityLog::query()->sole()->only(['user_id', 'pelaku', 'aksi']))
        ->toBe(['user_id' => null, 'pelaku' => 'orang@asing.test', 'aksi' => 'Gagal masuk']);
});

test('five wrong passwords lock the account for 15 minutes, even for the right one, and tell the supervisor', function () {
    foreach (range(1, 5) as $kali) {
        ($this->salah)();
    }

    // past the per-minute rate limit: what refuses now is the lock
    $this->travel(2)->minutes();
    $this->post('/masuk', ['email' => 'falah@bangbana.id', 'password' => 'Benar#1234'])
        ->assertSessionHasErrors(['email' => 'Akun ini dikunci 15 menit karena kata sandi salah berulang kali. Supervisor sudah dikabari.']);
    $this->assertGuest();

    $kabar = Notifikasi::query()->sole();
    expect($kabar->jenis)->toBe(JenisNotifikasi::LoginMencurigakan->value)
        ->and($kabar->judul)->toBe('Akun dikunci 15 menit: kata sandi salah 5 kali')
        ->and($kabar->detail)->toContain('Falah');

    $this->travel(14)->minutes();
    $this->post('/masuk', ['email' => 'falah@bangbana.id', 'password' => 'Benar#1234'])->assertRedirect('/');
    $this->assertAuthenticatedAs($this->staf);
});

test('the count follows the account, whether it is typed as email or name', function () {
    foreach (['falah@bangbana.id', 'Falah', 'falah@bangbana.id', 'falah', 'FALAH@bangbana.id'] as $masukan) {
        ($this->salah)($masukan);
    }

    $this->travel(2)->minutes();
    $this->post('/masuk', ['email' => 'falah', 'password' => 'Benar#1234'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('the right password starts the count again', function () {
    foreach (range(1, 4) as $kali) {
        ($this->salah)();
    }
    $this->post('/masuk', ['email' => 'falah@bangbana.id', 'password' => 'Benar#1234'])->assertRedirect('/');
    $this->post('/keluar');

    $this->travel(2)->minutes();
    ($this->salah)();
    $this->post('/masuk', ['email' => 'falah@bangbana.id', 'password' => 'Benar#1234'])->assertRedirect('/');
    expect(Notifikasi::query()->count())->toBe(0);
});

test('a lock notice cannot be switched off in Pengaturan', function () {
    ConfigToko::create(['kunci' => 'NOTIF_WA_MATI', 'nilai' => implode(',', KabarWa::BARIS)]);
    $kabar = KabarBot::notifikasi(JenisNotifikasi::LoginMencurigakan, 'Akun dikunci', 'Falah');

    expect(KabarWa::baris($kabar))->toBe('keamanan')
        ->and(KabarWa::mati())->not->toContain('keamanan');
});
