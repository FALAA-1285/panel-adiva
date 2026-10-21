<?php

use App\Enums\Peran;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('creates a supervisor with password', function () {
    $this->artisan('buat:supervisor', [
        'nama' => 'Owner Toko',
        'email' => 'owner@bangbana.id',
        'nomor_wa' => '081234567890',
        '--password' => 'Rahasia123!@#',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'owner@bangbana.id')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Owner Toko')
        ->and($user->nomor_wa)->toBe('6281234567890')
        ->and($user->peran)->toBe(Peran::Supervisor)
        ->and(Hash::check('Rahasia123!@#', (string) $user->password))->toBeTrue();
});

test('creates a supervisor without password to be registered via daftar', function () {
    $this->artisan('buat:supervisor', [
        'nama' => 'Supervisor Baru',
        'email' => 'spv@bangbana.id',
        'nomor_wa' => '6289876543210',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'spv@bangbana.id')->first();

    expect($user)->not->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->diundang_at)->not->toBeNull()
        ->and($user->peran)->toBe(Peran::Supervisor);
});

test('fails on invalid email or duplicate', function () {
    $this->artisan('buat:supervisor', [
        'nama' => 'Gagal',
        'email' => 'invalid-email',
        'nomor_wa' => '08123',
    ])->assertFailed();

    User::factory()->create(['email' => 'sama@bangbana.id']);

    $this->artisan('buat:supervisor', [
        'nama' => 'Duplikat',
        'email' => 'sama@bangbana.id',
        'nomor_wa' => '081234567899',
    ])->assertFailed();
});
