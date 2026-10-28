<?php

use App\Enums\KategoriAktivitas;
use App\Enums\Peran;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PengirimRekam;
use Tests\Support\SesiPanel;
use Tests\Support\UserAgent;

beforeEach(function () {
    ConfigToko::create(['kunci' => 'SESI_UTAMA', 'nilai' => 'bangbana-waha']);
    $this->falah = User::factory()->supervisor()->create(['name' => 'Falah', 'nomor_wa' => '6281234560007']);
});

/**
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function isianStaf(array $ubah = []): array
{
    return ['nama' => 'Rizky', 'email' => 'Rizky@BangBana.id', 'nomor_wa' => '0821 1234 0052', 'peran' => 'STAF_CS', 'cabang' => 'Lawang', ...$ubah];
}

test('Undang staf adds them to the list and sends the WhatsApp invitation', function () {
    $rekam = PengirimRekam::pasang();

    $this->actingAs($this->falah)->post('/tim/staf', isianStaf())->assertRedirect()->assertSessionHasNoErrors();

    $rizky = User::query()->where('email', 'rizky@bangbana.id')->sole();
    expect($rizky->only(['name', 'nomor_wa', 'cabang', 'password']))->toBe(['name' => 'Rizky', 'nomor_wa' => '6282112340052', 'cabang' => 'Lawang', 'password' => null])
        ->and($rizky->peran)->toBe(Peran::StafCs)
        ->and($rizky->diundang_at)->not->toBeNull()
        ->and($rekam->terkirim[0]['chat_id'])->toBe('6282112340052@c.us')
        ->and($rekam->terkirim[0]['teks'])->toBe('Halo Rizky, Anda diundang ke panel admin Adiva (Bang Bana) sebagai Staf CS. Buat akun di '.url('/daftar').' dengan email rizky@bangbana.id dan nomor ini.')
        ->and(ActivityLog::query()->where('kategori', KategoriAktivitas::Pengaturan->value)->value('aksi'))->toBe('Undang staf');

    $this->actingAs($this->falah)->get('/tim')->assertInertia(fn (Assert $page) => $page
        ->where('staf.1.nama', 'Rizky')
        ->where('staf.1.akses', ['label' => 'Undangan terkirim', 'nada' => 'amber'])
        ->where('staf.1.belumDaftar', true)
        ->where('staf.1.isian.nomor_wa', '6282112340052'));
});

test('an invitation WhatsApp refuses keeps the staff member and says so', function () {
    PengirimRekam::pasang(tolak: 'WAHA tidak bisa dihubungi');

    $this->actingAs($this->falah)->post('/tim/staf', isianStaf())
        ->assertSessionHasErrors(['staf' => 'Staf ditambahkan, tetapi undangan WhatsApp gagal: WAHA tidak bisa dihubungi. Coba Kirim ulang undangan.']);

    expect(User::query()->where('email', 'rizky@bangbana.id')->exists())->toBeTrue();
});

test('email and number must be new, and the fields are required', function () {
    PengirimRekam::pasang();

    $this->actingAs($this->falah)->post('/tim/staf', isianStaf(['email' => 'lain@bangbana.id', 'nomor_wa' => '812 3456 0007']))
        ->assertSessionHasErrors(['nomor_wa' => 'Nomor ini sudah dipakai staf lain.']);
    $this->actingAs($this->falah)->post('/tim/staf', isianStaf(['nama' => '', 'email' => 'bukan-email', 'peran' => 'RAJA']))
        ->assertSessionHasErrors(['nama', 'email', 'peran']);
});

test('Ubah changes a staff member, but a supervisor cannot lower their own role', function () {
    $dina = User::factory()->create(['email' => 'dina@bangbana.id', 'nomor_wa' => '6285712340036']);

    $this->actingAs($this->falah)->put("/tim/staf/{$dina->id}", isianStaf(['nama' => 'Dina Lestari', 'email' => 'dina@bangbana.id', 'nomor_wa' => '6285712340036', 'peran' => 'SUPERVISOR']))
        ->assertSessionHasNoErrors();
    expect($dina->refresh()->name)->toBe('Dina Lestari')->and($dina->peran)->toBe(Peran::Supervisor);

    $this->actingAs($this->falah)->put("/tim/staf/{$this->falah->id}", isianStaf(['nama' => 'Falah', 'email' => $this->falah->email, 'nomor_wa' => '6281234560007', 'peran' => 'STAF_CS']))
        ->assertSessionHasErrors(['peran' => 'Peran Anda sendiri tidak bisa diturunkan.']);
});

test('Nonaktifkan ends their sessions, Aktifkan lagi lets them back in', function () {
    $dina = User::factory()->create();
    SesiPanel::buat($dina, UserAgent::ANDROID, '-1 hour');

    $this->actingAs($this->falah)->post("/tim/staf/{$dina->id}/nonaktif")->assertSessionHasNoErrors();
    expect($dina->refresh()->nonaktif_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $dina->id)->exists())->toBeFalse();

    $this->actingAs($this->falah)->post("/tim/staf/{$dina->id}/aktif");
    expect($dina->refresh()->nonaktif_at)->toBeNull()
        ->and(ActivityLog::query()->pluck('aksi')->all())->toBe(['Nonaktifkan staf', 'Aktifkan staf']);
});

test('a deactivated staff member still signed in is signed out on their next request', function () {
    $dina = User::factory()->create();
    $dina->forceFill(['nonaktif_at' => now()])->save();

    $this->actingAs($dina)->get('/pesanan')
        ->assertRedirect('/masuk')
        ->assertSessionHasErrors(['email' => 'Akun ini sudah dinonaktifkan. Hubungi supervisor.']);
    $this->assertGuest();
});

test('Nonaktifkan voids "Ingat perangkat ini" on every device', function () {
    $dina = User::factory()->create(['remember_token' => 'token-lama']);

    $this->actingAs($this->falah)->post("/tim/staf/{$dina->id}/nonaktif");

    expect($dina->refresh()->remember_token)->not->toBe('token-lama');
});

test('a supervisor cannot deactivate themselves', function () {
    $this->actingAs($this->falah)->post("/tim/staf/{$this->falah->id}/nonaktif")
        ->assertSessionHasErrors(['staf' => 'Anda tidak bisa menonaktifkan diri sendiri.']);

    expect($this->falah->refresh()->nonaktif_at)->toBeNull();
});

test('the invitation can be sent again until the account is made', function () {
    $rekam = PengirimRekam::pasang();
    $baru = User::factory()->create(['name' => 'Rizky', 'password' => null]);
    $sudah = User::factory()->create(['name' => 'Dina']);

    $this->actingAs($this->falah)->post("/tim/staf/{$baru->id}/undangan")->assertSessionHasNoErrors();
    $this->actingAs($this->falah)->post("/tim/staf/{$sudah->id}/undangan")->assertSessionHasErrors(['staf' => 'Dina sudah membuat akun.']);

    expect($rekam->terkirim)->toHaveCount(1);
});

test('only supervisors may manage staff', function () {
    $cs = User::factory()->create(['peran' => Peran::StafCs]);

    $this->actingAs($cs)->post('/tim/staf', isianStaf())->assertForbidden();
    $this->actingAs($cs)->put("/tim/staf/{$this->falah->id}", isianStaf())->assertForbidden();
    $this->actingAs($cs)->post("/tim/staf/{$this->falah->id}/nonaktif")->assertForbidden();
});
