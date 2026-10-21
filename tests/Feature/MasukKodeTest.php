<?php

use App\Models\ConfigToko;
use App\Models\User;
use Tests\Support\PengirimRekam;

beforeEach(function () {
    ConfigToko::create(['kunci' => 'SESI_UTAMA', 'nilai' => 'bangbana-waha']);
});

test('the sign-in code goes to the WhatsApp number of the email typed', function () {
    User::factory()->falah()->create(['password' => 'rahasia123']);
    $rekam = PengirimRekam::pasang();

    $this->postJson('/masuk/kode', ['email' => 'Falah@BangBana.id'])
        ->assertOk()
        ->assertExactJson(['nomor' => '+62 812-••••-••07']);

    expect($rekam->terkirim[0]['chat_id'])->toBe('6281234560007@c.us')
        ->and($rekam->terkirim[0]['teks'])->toStartWith('Kode masuk panel Adiva: ');
});

test('the right code signs the staff member in and remembers the device', function () {
    $falah = User::factory()->falah()->create(['password' => 'rahasia123']);
    $rekam = PengirimRekam::pasang();
    $this->postJson('/masuk/kode', ['email' => 'falah@bangbana.id']);

    $this->post('/masuk/kode/verifikasi', ['kode' => $rekam->kodeTerakhir(), 'remember' => true])->assertRedirect('/');

    $this->assertAuthenticatedAs($falah);
    expect($falah->refresh()->remember_token)->not->toBeNull()
        ->and($falah->terakhir_masuk_at)->not->toBeNull();
});

test('no code without an email, or for staff who are inactive or have no account', function (array $ubah, string $email, string $pesan) {
    User::factory()->falah()->create(['password' => 'rahasia123', ...$ubah]);
    PengirimRekam::pasang();

    $this->postJson('/masuk/kode', ['email' => $email])->assertJsonValidationErrors(['email' => $pesan]);
})->with([
    'tanpa email' => [[], '', 'Isi email kerja dulu.'],
    'tidak dikenal' => [[], 'orang@lain.id', 'Email ini tidak ada di daftar staf aktif.'],
    'nonaktif' => [['nonaktif_at' => now()], 'falah@bangbana.id', 'Email ini tidak ada di daftar staf aktif.'],
    'belum daftar' => [['password' => null], 'falah@bangbana.id', 'Email ini tidak ada di daftar staf aktif.'],
]);

test('three wrong codes end the attempt', function () {
    User::factory()->falah()->create(['password' => 'rahasia123']);
    $rekam = PengirimRekam::pasang();
    $this->postJson('/masuk/kode', ['email' => 'falah@bangbana.id']);
    $kode = $rekam->kodeTerakhir();
    $salah = $kode === '000000' ? '111111' : '000000';

    foreach (range(1, 3) as $_) {
        $this->post('/masuk/kode/verifikasi', ['kode' => $salah])->assertSessionHasErrors(['kode' => 'Kode salah.']);
    }

    $this->post('/masuk/kode/verifikasi', ['kode' => $kode])->assertSessionHasErrors(['kode' => 'Kode sudah tidak berlaku. Kirim ulang kode.']);
    $this->assertGuest();
});

test('deactivated staff cannot sign in with their password either', function () {
    User::factory()->falah()->create(['password' => 'rahasia123', 'nonaktif_at' => now()]);

    $this->post('/masuk', ['email' => 'falah@bangbana.id', 'password' => 'rahasia123'])
        ->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);
    $this->assertGuest();
});
