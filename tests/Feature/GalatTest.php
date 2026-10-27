<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * A missing or refused page is shown inside the panel with a way back, not as
 * Laravel's bare error page (Audit Panel Adiva: old order links left people stranded).
 */

test('an unknown order is a 404 inside the panel', function () {
    $this->actingAs(User::factory()->falah()->create())
        ->get('/pesanan/ORD-TIDAK-ADA')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('galat')->where('status', 404));
});

test('an unknown address is a 404 page too', function () {
    $this->get('/halaman-tidak-ada')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('galat')->where('status', 404));
});

test('a page the role may not open says so inside the panel', function () {
    $this->actingAs(User::factory()->create())
        ->get('/monitoring')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('galat')->where('status', 403));
});

test('JSON callers still get JSON', function () {
    $this->actingAs(User::factory()->falah()->create())
        ->getJson('/pesanan/ORD-TIDAK-ADA')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});
