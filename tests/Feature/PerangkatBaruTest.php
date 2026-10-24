<?php

use App\Models\ConfigToko;
use App\Models\User;
use App\Support\PerangkatDikenal;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PengirimRekam;

beforeEach(function () {
    ConfigToko::create(['kunci' => 'SESI_UTAMA', 'nilai' => 'bangbana-waha']);
    $this->falah = User::factory()->falah()->create(['password' => 'rahasia123']);
});

test('the right password on a new device asks for a WhatsApp code first', function () {
    $rekam = PengirimRekam::pasang();

    $this->post('/masuk', ['email' => 'falah@bangbana.id', 'password' => 'rahasia123', 'remember' => true])->assertRedirect('/masuk');

    $this->assertGuest();
    expect($rekam->terkirim[0]['chat_id'])->toBe('6281234560007@c.us')
        ->and($rekam->terkirim[0]['teks'])->toStartWith('Kode masuk panel Adiva: ');
    $this->get('/masuk')->assertInertia(fn (Assert $page) => $page->where('kodePerangkat', ['nomor' => '+62 812-••••-••07']));

    $this->post('/masuk/kode/verifikasi', ['kode' => $rekam->kodeTerakhir()])
        ->assertRedirect('/')
        ->assertCookie(PerangkatDikenal::COOKIE);

    $this->assertAuthenticatedAs($this->falah);
    // "Ingat perangkat ini" ticked with the password still applies
    expect($this->falah->refresh()->remember_token)->not->toBeNull();
});

test('a known device signs in straight away', function () {
    $rekam = PengirimRekam::pasang();

    $this->withCookie(PerangkatDikenal::COOKIE, (string) json_encode([$this->falah->id]))
        ->post('/masuk', ['email' => 'falah@bangbana.id', 'password' => 'rahasia123'])
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($this->falah);
    expect($rekam->terkirim)->toBe([]);
});

test('with the switch off, or a wrong password, no code is sent', function () {
    $rekam = PengirimRekam::pasang();

    $this->post('/masuk', ['email' => 'falah@bangbana.id', 'password' => 'salah-sekali'])
        ->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);
    expect($rekam->terkirim)->toBe([]);

    ConfigToko::create(['kunci' => 'PANEL_KODE_PERANGKAT_BARU', 'nilai' => '0']);
    $this->post('/masuk', ['email' => 'falah@bangbana.id', 'password' => 'rahasia123'])->assertRedirect('/');
    $this->assertAuthenticatedAs($this->falah);
    expect($rekam->terkirim)->toBe([]);
});

test('signing in with the WhatsApp code also marks the device as known', function () {
    $rekam = PengirimRekam::pasang();
    $this->postJson('/masuk/kode', ['email' => 'falah@bangbana.id']);

    $this->post('/masuk/kode/verifikasi', ['kode' => $rekam->kodeTerakhir()])->assertCookie(PerangkatDikenal::COOKIE);
});
