<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The panel speaks Indonesian even where the server sets no APP_LOCALE (Vercel):
 * the page says lang="id" and dates read "Senin, 28 Sep".
 */

test('the default locale is Indonesian, not Laravel\'s English', function () {
    $isi = file_get_contents(config_path('app.php'));

    expect($isi)->toContain("'locale' => env('APP_LOCALE', 'id')");
});

test('the page is marked Indonesian and its dates are Indonesian', function () {
    $this->actingAs(User::factory()->falah()->create())
        ->get('/')
        ->assertSee('<html lang="id">', false)
        ->assertInertia(fn (Assert $page) => $page->where('panel.waktu', fn (string $w) => str_starts_with($w, 'Senin, 28 Sep')));
});
