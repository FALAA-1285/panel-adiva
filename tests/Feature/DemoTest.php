<?php

use App\Enums\Peran;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('the clock is frozen to Monday 28 Sep 2026 13:42 WIB', function () {
    $now = now();

    expect($now->timezone->getName())->toBe('Asia/Jakarta')
        ->and($now->format('Y-m-d H:i'))->toBe('2026-09-28 13:42')
        ->and($now->translatedFormat('l, j M'))->toBe('Senin, 28 Sep');
});

test('web requests see the frozen clock', function () {
    Date::setTestNow();
    Route::middleware('web')->get('/_tes-jam', fn () => now()->format('Y-m-d H:i T'));

    $this->get('/_tes-jam')->assertSeeText('2026-09-28 13:42 WIB');
});

test('cookies are stamped with the real time so browsers keep them', function () {
    Date::setTestNow();

    $response = $this->get(route('demo.login'));

    $cookie = collect($response->headers->getCookies())
        ->firstWhere(fn ($cookie) => $cookie->getName() === config('session.cookie'));
    expect($cookie->getExpiresTime())->toBeGreaterThan(time())
        ->and(Date::hasTestNow())->toBeFalse();
});

test('demo login signs in as Falah the supervisor', function () {
    $response = $this->get(route('demo.login'));

    $response->assertRedirect('/');

    $user = User::sole();
    expect($user->name)->toBe('Falah')
        ->and($user->email)->toBe('falah@bangbana.id')
        ->and($user->peran)->toBe(Peran::Supervisor);
    $this->assertAuthenticatedAs($user);
});

test('demo login reuses the existing Falah account', function () {
    $falah = User::factory()->supervisor()->create(['name' => 'Falah', 'email' => 'falah@bangbana.id']);

    $this->get(route('demo.login'));

    expect(User::count())->toBe(1);
    $this->assertAuthenticatedAs($falah);
});

test('component previews render their own page', function (string $komponen) {
    $this->get(route('demo.komponen', $komponen))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('demo/komponen-'.$komponen));
})->with(['sidebar', 'topbar']);

test('unknown component previews are not found', function () {
    $this->get('/_demo/komponen/panel')->assertNotFound();
});

test('demo routes are not found when demo mode is off', function (string $uri) {
    config(['app.demo' => false]);

    $this->get($uri)->assertNotFound();
    $this->assertGuest();
})->with(['/_demo/login', '/_demo/komponen/sidebar', '/_demo/komponen/topbar']);

afterEach(function () {
    Demo::bekukanJam();
});
