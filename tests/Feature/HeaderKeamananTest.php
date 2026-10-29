<?php

use App\Models\User;

test('every page carries the security headers', function () {
    $response = $this->actingAs(User::factory()->falah()->create())->get('/pesanan');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/");
});

test('the scripts Vite writes carry the same nonce as the policy', function () {
    $response = $this->get('/masuk');

    preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $m);
    expect($m[1] ?? null)->not->toBeNull()
        ->and($response->getContent())->toContain('nonce="'.$m[1].'"');
});

test('guest pages and missing pages carry the headers too', function () {
    $this->get('/masuk')->assertHeader('X-Frame-Options', 'DENY');
    $this->get('/tidak-ada-halaman-ini')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('HSTS is sent over HTTPS only', function () {
    $this->get('/masuk')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/masuk')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
