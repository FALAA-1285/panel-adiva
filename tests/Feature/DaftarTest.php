<?php

use App\Mail\KodeVerifikasiMail;
use App\Models\ConfigToko;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AlurDaftar;
use Tests\Support\PengirimRekam;

beforeEach(function () {
    ConfigToko::create(['kunci' => 'SESI_UTAMA', 'nilai' => 'bangbana-waha']);
});

test('staff on the list get a code, a masked number and their staff card', function () {
    User::factory()->dina()->create();
    PengirimRekam::pasang();

    $this->postJson('/daftar/kode', AlurDaftar::isian())
        ->assertOk()
        ->assertExactJson([
            'nomor' => '+62 857-••••-••36',
            'staf' => ['nama' => 'Dina', 'inisial' => 'D', 'email' => 'dina@bangbana.id', 'peran' => 'Staf CS'],
        ]);
});

test('the code reaches the staff member\'s WhatsApp from the main bot number', function () {
    User::factory()->dina()->create();
    $rekam = PengirimRekam::pasang();

    $this->postJson('/daftar/kode', AlurDaftar::isian())->assertOk();

    expect($rekam->terkirim)->toHaveCount(1)
        ->and($rekam->terkirim[0]['sesi'])->toBe('bangbana-waha')
        ->and($rekam->terkirim[0]['chat_id'])->toBe('6285712340036@c.us')
        ->and($rekam->terkirim[0]['teks'])->toBe("Kode verifikasi panel Adiva: {$rekam->kodeTerakhir()}\nBerlaku 5 menit. Jangan berikan kode ini ke siapa pun.");
});

test('a code WhatsApp did not take is not kept', function () {
    User::factory()->dina()->create();
    PengirimRekam::pasang(tolak: 'WAHA menolak (422): session STOPPED');

    $this->postJson('/daftar/kode', AlurDaftar::isian())
        ->assertJsonValidationErrors(['nomor_wa' => 'Kode gagal dikirim ke WhatsApp. Coba lagi sebentar.']);

    expect(session('daftar'))->toBeNull();
});

test('Kirim lewat email sends a new code to the staff email, and the WhatsApp one stops working', function () {
    Mail::fake();
    $dina = User::factory()->dina()->create();
    $kodeWa = AlurDaftar::mintaKode($this);

    $this->postJson('/daftar/kode-email')->assertOk()->assertExactJson(['tujuan' => 'd•••@bangbana.id']);

    $kodeEmail = '';
    Mail::assertSent(KodeVerifikasiMail::class, function (KodeVerifikasiMail $mail) use (&$kodeEmail) {
        $kodeEmail = $mail->kode;

        return $mail->hasTo('dina@bangbana.id');
    });

    if ($kodeEmail !== $kodeWa) {
        $this->post('/daftar/verifikasi', ['kode' => $kodeWa])->assertSessionHasErrors('kode');
    }
    $this->post('/daftar/verifikasi', ['kode' => $kodeEmail])->assertRedirect('/');
    $this->assertAuthenticatedAs($dina);
});

test('Kirim lewat email needs the first step', function () {
    Mail::fake();

    $this->postJson('/daftar/kode-email')->assertJsonValidationErrors(['kode' => 'Kirim kode lewat WhatsApp dulu.']);

    Mail::assertNothingSent();
});

test('no code is sent when the number or email is not on the staff list', function (array $ubah) {
    User::factory()->dina()->create();

    $this->postJson('/daftar/kode', AlurDaftar::isian($ubah))->assertJsonValidationErrors('nomor_wa');
})->with([
    'nomor lain' => [['nomor_wa' => '812 0000 0000']],
    'email lain' => [['email' => 'bukan-dina@bangbana.id']],
]);

test('staff who already have a panel password cannot register again', function () {
    User::factory()->dina()->create(['password' => 'sudah-ada-123']);

    $this->postJson('/daftar/kode', AlurDaftar::isian())->assertJsonValidationErrors('nomor_wa');
});

test('the password needs 8 characters with letters and numbers, and consent', function (array $ubah, string $kolom) {
    User::factory()->dina()->create();

    $this->postJson('/daftar/kode', AlurDaftar::isian($ubah))->assertJsonValidationErrors($kolom);
})->with([
    'terlalu pendek' => [['password' => 'abc123'], 'password'],
    'tanpa angka' => [['password' => 'rahasiasaja'], 'password'],
    'tidak setuju' => [['setuju' => false], 'setuju'],
]);

test('the right code saves the password and logs the staff member in', function () {
    $dina = User::factory()->dina()->create();
    $kode = AlurDaftar::mintaKode($this);

    $this->post('/daftar/verifikasi', ['kode' => $kode])->assertRedirect('/');

    $this->assertAuthenticatedAs($dina);
    expect(Hash::check('rahasia123', $dina->fresh()->password))->toBeTrue();
});

test('three wrong codes cancel the registration', function () {
    User::factory()->dina()->create();
    $kode = AlurDaftar::mintaKode($this);
    $salah = $kode === '000000' ? '111111' : '000000';

    foreach (range(1, 3) as $_) {
        $this->post('/daftar/verifikasi', ['kode' => $salah])->assertSessionHasErrors('kode');
    }

    $this->post('/daftar/verifikasi', ['kode' => $kode])->assertSessionHasErrors('kode');
    $this->assertGuest();
});
