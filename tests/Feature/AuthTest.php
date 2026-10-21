<?php

use App\Models\ConfigToko;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected from the panel to the login page', function () {
    $this->get(route('home'))->assertRedirect('/masuk');
});

test('guest pages render their own component', function (string $uri, string $component) {
    $this->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['/masuk', 'auth/masuk'],
    ['/daftar', 'auth/daftar'],
    ['/lupa-sandi', 'auth/lupa-sandi'],
]);

test('staff can log in with email and password', function () {
    // the new-device code has its own tests (PerangkatBaruTest)
    ConfigToko::create(['kunci' => 'PANEL_KODE_PERANGKAT_BARU', 'nilai' => '0']);
    $user = User::factory()->create();

    $response = $this->post('/masuk', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

test('"Ingat perangkat ini selama 30 hari" keeps the login for 30 days', function () {
    // the new-device code has its own tests (PerangkatBaruTest)
    ConfigToko::create(['kunci' => 'PANEL_KODE_PERANGKAT_BARU', 'nilai' => '0']);
    $user = User::factory()->create();

    $response = $this->post('/masuk', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => 'on',
    ]);

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime())->toBe(now()->addDays(30)->getTimestamp());
});

test('login fails with a wrong password', function () {
    $user = User::factory()->create();

    $this->post('/masuk', [
        'email' => $user->email,
        'password' => 'salah',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('logged in staff see the panel home', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ringkasan'));
});

test('staff can log out', function () {
    $this->actingAs(User::factory()->create())
        ->post('/keluar')
        ->assertRedirect('/');

    $this->assertGuest();
});

test('registration and email password reset from Fortify are disabled', function () {
    $this->post('/register', [])->assertNotFound();
    $this->post('/forgot-password', [])->assertNotFound();
});
