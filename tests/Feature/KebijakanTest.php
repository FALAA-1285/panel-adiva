<?php

use App\Models\Kebijakan;
use App\Models\PemakaianKebijakan;
use App\Models\UjiAdiva;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to the login page', function () {
    $this->get('/katalog/kebijakan')->assertRedirect('/masuk');
});

test('policies show their use in the last 30 days and the latest three questions', function () {
    // trigger words as n8n may store them: extra spaces and a trailing comma
    $kebijakan = Kebijakan::factory()->ongkir()->create(['kata_pemicu' => 'ongkir, kirim,  JNE ,']);
    $pakai = fn (string $waktu, ?string $pertanyaan = null) => PemakaianKebijakan::factory()
        ->for($kebijakan)->pada($waktu)->create(['pertanyaan' => $pertanyaan]);

    $pakai('2026-09-27 10:00', 'bisa kirim pakai gosend?');
    $pakai('2026-09-28 11:00', 'ongkir ke surabaya brp kak');
    $pakai('2026-09-26 10:00', 'kirim dari mana ya, malang kota?');
    $pakai('2026-09-20 10:00', 'pertanyaan lama');
    $pakai('2026-09-10 10:00');
    $pakai('2026-08-20 10:00', 'di luar 30 hari');

    $this->actingAs(User::factory()->supervisor()->create())
        ->get('/katalog/kebijakan')
        ->assertInertia(fn (Assert $page) => $page
            ->component('katalog/kebijakan')
            ->where('kebijakan.0.dipakai', 5)
            ->where('kebijakan.0.kataPemicu', ['ongkir', 'kirim', 'JNE'])
            ->where('kebijakan.0.pertanyaan', ['ongkir ke surabaya brp kak', 'bisa kirim pakai gosend?', 'kirim dari mana ya, malang kota?']));
});

test('the latest policy test decides which policy opens first', function () {
    $kebijakan = Kebijakan::factory()->ongkir()->create();
    UjiAdiva::create(['bagian' => 'KEBIJAKAN', 'pertanyaan' => 'ongkir ke blitar brp kak?', 'kebijakan_id' => $kebijakan->id]);

    $this->actingAs(User::factory()->supervisor()->create())
        ->get('/katalog/kebijakan')
        ->assertInertia(fn (Assert $page) => $page
            ->where('uji.pertanyaan', 'ongkir ke blitar brp kak?')
            ->where('uji.kebijakanId', $kebijakan->id));
});

test('saving stores the title, text and trigger words', function () {
    $kebijakan = Kebijakan::factory()->ongkir()->create();

    $this->actingAs(User::factory()->supervisor()->create())
        ->put("/katalog/kebijakan/{$kebijakan->id}", [
            'topik' => 'Ongkir & pengiriman',
            'jawaban_resmi' => 'Berangkat tiap hari kerja.',
            'kata_pemicu' => ['ongkir', 'sicepat'],
        ])
        ->assertRedirect();

    $kebijakan->refresh();
    expect($kebijakan->topik)->toBe('Ongkir & pengiriman')
        ->and($kebijakan->jawaban_resmi)->toBe('Berangkat tiap hari kerja.')
        ->and($kebijakan->kata_pemicu)->toBe('ongkir, sicepat');
});

test('a title is required and trigger words are unique', function () {
    $kebijakan = Kebijakan::factory()->ongkir()->create();

    $this->actingAs(User::factory()->supervisor()->create())
        ->put("/katalog/kebijakan/{$kebijakan->id}", ['topik' => '', 'kata_pemicu' => ['ongkir', 'ongkir']])
        ->assertSessionHasErrors(['topik', 'kata_pemicu.1']);
});
