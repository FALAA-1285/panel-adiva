<?php

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // the demo's frozen clock never ages a password
    config(['app.demo' => false]);
    $this->staf = User::factory()->falah()->create(['password' => 'Lama#1234']);
});

test('setting a password starts its 90 days', function () {
    expect($this->staf->sandi_diubah_at?->equalTo(now()))->toBeTrue();

    $this->travel(30)->days();
    $this->staf->forceFill(['password' => 'Baru#1234'])->save();

    expect($this->staf->refresh()->sandi_diubah_at?->equalTo(now()))->toBeTrue();
});

test('a password younger than 90 days changes nothing', function () {
    $this->travel(89)->days();

    $this->actingAs($this->staf)->get('/pesanan')->assertOk();
});

test('after 90 days every page leads to Ganti kata sandi, except the page itself and Keluar', function () {
    $this->travel(90)->days();

    $this->actingAs($this->staf)->get('/pesanan')->assertRedirect('/ganti-sandi');
    $this->actingAs($this->staf)->getJson('/cari?q=rina')->assertStatus(409);
    $this->actingAs($this->staf)->get('/ganti-sandi')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/ganti-sandi')->where('wajib', true)->where('hari', 90));
    $this->actingAs($this->staf)->post('/keluar')->assertRedirect();
    $this->assertGuest();
});

test('the new password must differ from the old one and follow the rule', function (array $isian, string $kolom) {
    $this->travel(90)->days();

    $this->actingAs($this->staf)->put('/ganti-sandi', $isian)->assertSessionHasErrors($kolom);
    expect(Hash::check('Lama#1234', $this->staf->refresh()->password))->toBeTrue();
})->with([
    'sandi sekarang salah' => [['sandi_lama' => 'Salah#1234', 'password' => 'Baru#1234', 'password_confirmation' => 'Baru#1234'], 'sandi_lama'],
    'sama dengan yang lama' => [['sandi_lama' => 'Lama#1234', 'password' => 'Lama#1234', 'password_confirmation' => 'Lama#1234'], 'password'],
    'terlalu lemah' => [['sandi_lama' => 'Lama#1234', 'password' => 'baru1234', 'password_confirmation' => 'baru1234'], 'password'],
    'tidak sama' => [['sandi_lama' => 'Lama#1234', 'password' => 'Baru#1234', 'password_confirmation' => 'Baru#9999'], 'password'],
]);

test('a new password opens the panel again for 90 days and is logged', function () {
    $this->travel(91)->days();

    $this->actingAs($this->staf)
        ->put('/ganti-sandi', ['sandi_lama' => 'Lama#1234', 'password' => 'Baru#1234', 'password_confirmation' => 'Baru#1234'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect(Hash::check('Baru#1234', $this->staf->refresh()->password))->toBeTrue()
        ->and($this->staf->sandiKedaluwarsa())->toBeFalse()
        ->and(ActivityLog::query()->where('aksi', 'Ganti kata sandi')->exists())->toBeTrue();
    $this->actingAs($this->staf)->get('/pesanan')->assertOk();
});

test('the demo never asks for a new password', function () {
    config(['app.demo' => true]);
    $this->staf->forceFill(['sandi_diubah_at' => now()->subYear()])->saveQuietly();

    $this->actingAs($this->staf)->get('/pesanan')->assertOk();
});
