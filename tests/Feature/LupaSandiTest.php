<?php

use App\Enums\KategoriAktivitas;
use App\Http\Controllers\Auth\LupaSandiController;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\PengirimRekam;
use Tests\Support\UserAgent;

beforeEach(function () {
    ConfigToko::create(['kunci' => 'SESI_UTAMA', 'nilai' => 'bangbana-waha']);
});

test('the reset code reaches the staff member\'s WhatsApp', function () {
    User::factory()->falah()->create();
    $rekam = PengirimRekam::pasang();

    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007'])->assertOk();

    expect($rekam->terkirim)->toBe([[
        'sesi' => 'bangbana-waha',
        'chat_id' => '6281234560007@c.us',
        'teks' => "Kode reset kata sandi panel Adiva: 710593\nBerlaku 5 menit. Jangan berikan kode ini ke siapa pun.",
        'gambar' => null,
    ]]);
});

test('a code WhatsApp did not take does not use up a send', function () {
    User::factory()->falah()->create();
    PengirimRekam::pasang(tolak: 'WAHA tidak bisa dihubungi');

    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007'])
        ->assertJsonValidationErrors(['nomor_wa' => 'Kode gagal dikirim ke WhatsApp. Coba lagi sebentar.']);

    PengirimRekam::pasang();
    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007'])->assertJsonPath('sisaKirim', 2);
});

test('the supervisor hears about a locked reset', function () {
    config(['app.demo' => false]);
    ConfigToko::create(['kunci' => 'ADMIN_WA', 'nilai' => '6281200000001']);
    User::factory()->falah()->create();
    $rekam = PengirimRekam::pasang();
    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007']);
    $salah = $rekam->kodeTerakhir() === '000000' ? '111111' : '000000';

    foreach (range(1, 3) as $_) {
        $this->postJson('/lupa-sandi/cek-kode', ['kode' => $salah]);
    }

    expect(end($rekam->terkirim))->toMatchArray([
        'chat_id' => '6281200000001@c.us',
        'teks' => 'Reset kata sandi Falah dikunci 15 menit: kode salah tiga kali.',
    ]);
});

test('active staff get a reset code with the masked number and resends left', function () {
    User::factory()->falah()->create();

    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007'])
        ->assertOk()
        ->assertExactJson(['nomor' => '+62 812-••••-••07', 'sisaKirim' => 2]);
});

test('no code for numbers outside the staff list or for deactivated staff', function (string $nomor) {
    User::factory()->falah()->create(['nonaktif_at' => now()]);
    User::factory()->create(['nomor_wa' => '6281111110001']);

    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => $nomor])->assertJsonValidationErrors('nomor_wa');
})->with(['bukan staf' => '812 0000 0000', 'nonaktif' => '812 3456 0007']);

test('a code can be sent three times per reset', function () {
    User::factory()->falah()->create();

    foreach ([2, 1, 0] as $sisa) {
        $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007'])->assertJsonPath('sisaKirim', $sisa);
    }

    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007'])->assertJsonValidationErrors('nomor_wa');
});

test('the right code unlocks the new-password step and names the account', function () {
    User::factory()->falah()->create();
    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007']);

    $this->postJson('/lupa-sandi/cek-kode', ['kode' => LupaSandiController::KODE_DEMO])
        ->assertOk()
        ->assertExactJson(['email' => 'falah@bangbana.id']);
});

test('three wrong codes lock the reset for 15 minutes', function () {
    // outside demo mode, so the clock can move forward between requests
    config(['app.demo' => false]);
    User::factory()->falah()->create();
    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007']);

    foreach (range(1, 3) as $_) {
        $this->postJson('/lupa-sandi/cek-kode', ['kode' => '000000'])->assertJsonValidationErrors('kode');
    }

    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007'])->assertJsonValidationErrors('kode');

    $this->travel(16)->minutes();

    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007'])->assertOk();
});

test('code requests are rate limited outside demo mode', function () {
    config(['app.demo' => false]);
    User::factory()->falah()->create();

    foreach (range(1, 5) as $_) {
        expect($this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007'])->status())->not->toBe(429);
    }

    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007'])->assertTooManyRequests();
});

test('the new password cannot be saved before the code is checked', function () {
    User::factory()->falah()->create();
    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007']);

    $this->post('/lupa-sandi/simpan', [
        'password' => 'sandibaru123',
        'password_confirmation' => 'sandibaru123',
    ])->assertSessionHasErrors('password');
});

test('saving the new password logs out other devices and is written to the activity log', function () {
    $falah = User::factory()->falah()->create(['password' => 'sandilama123', 'remember_token' => 'token-lama']);
    DB::table('sessions')->insert([
        'id' => 'sesi-lain', 'user_id' => $falah->id, 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);
    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007']);
    $this->postJson('/lupa-sandi/cek-kode', ['kode' => LupaSandiController::KODE_DEMO]);

    $this->withHeader('User-Agent', UserAgent::WINDOWS)
        ->post('/lupa-sandi/simpan', [
            'password' => 'sandibaru123',
            'password_confirmation' => 'sandibaru123',
            'keluarkan' => '1',
        ])
        ->assertRedirect(route('login'));

    $falah->refresh();
    expect(Hash::check('sandibaru123', $falah->password))->toBeTrue()
        ->and($falah->remember_token)->not->toBe('token-lama')
        ->and(DB::table('sessions')->where('user_id', $falah->id)->exists())->toBeFalse();

    $log = ActivityLog::sole();
    expect($log->pelaku)->toBe('Falah')
        ->and($log->kategori)->toBe(KategoriAktivitas::Login->value)
        ->and($log->aksi)->toBe('Reset kata sandi')
        ->and($log->detail)->toBe('Chrome · Windows · perangkat lain dikeluarkan');
});

test('the new password must be typed twice the same', function () {
    User::factory()->falah()->create();
    $this->postJson('/lupa-sandi/kode', ['nomor_wa' => '812 3456 0007']);
    $this->postJson('/lupa-sandi/cek-kode', ['kode' => LupaSandiController::KODE_DEMO]);

    $this->post('/lupa-sandi/simpan', [
        'password' => 'sandibaru123',
        'password_confirmation' => 'sandilain123',
    ])->assertSessionHasErrors('password');
});
