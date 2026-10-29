<?php

use App\Models\ConfigToko;
use App\Models\User;
use App\Support\PengaturanKeamanan;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    ConfigToko::create(['kunci' => 'PANEL_KODE_PERANGKAT_BARU', 'nilai' => '0']);
});

test('in production the new-device code stays on even when the setting says off', function () {
    config(['app.demo' => false]);
    app()['env'] = 'production';

    expect(PengaturanKeamanan::kodePerangkatWajib())->toBeTrue()
        ->and(PengaturanKeamanan::kodePerangkatBaru())->toBeTrue();
});

test('the public demo and other environments keep the switch', function () {
    app()['env'] = 'production';
    config(['app.demo' => true]);
    expect(PengaturanKeamanan::kodePerangkatBaru())->toBeFalse();

    app()['env'] = 'testing';
    config(['app.demo' => false]);
    expect(PengaturanKeamanan::kodePerangkatWajib())->toBeFalse()
        ->and(PengaturanKeamanan::kodePerangkatBaru())->toBeFalse();
});

test('Tim & akses shows the switch locked in production and refuses to turn it off', function () {
    config(['app.demo' => false]);
    app()['env'] = 'production';
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)->get('/tim')
        ->assertInertia(fn (Assert $page) => $page->where('keamanan.kodePerangkatBaru', true)->where('keamanan.kodePerangkatWajib', true));

    // Laravel only skips the CSRF check in the testing environment, and this is "production"
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->actingAs($falah)->patch('/tim/keamanan', ['kode_perangkat_baru' => false])
        ->assertSessionHasErrors(['kode_perangkat_baru' => 'Kode WhatsApp di perangkat baru wajib menyala di panel produksi.']);
    expect(ConfigToko::query()->where('kunci', 'PANEL_KODE_PERANGKAT_BARU')->value('nilai'))->toBe('0');
});

afterEach(function () {
    app()['env'] = 'testing';
});
