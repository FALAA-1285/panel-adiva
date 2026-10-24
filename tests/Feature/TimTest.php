<?php

use App\Enums\KategoriAktivitas;
use App\Enums\Peran;
use App\Enums\SumberAktivitas;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\User;
use App\Support\PengaturanKeamanan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SesiPanel;
use Tests\Support\UserAgent;

test('guests are sent to the login page', function () {
    $this->get('/tim')->assertRedirect('/masuk');
});

test('only supervisors may open Tim & akses', function (Peran $peran) {
    $this->actingAs(User::factory()->create(['peran' => $peran]))
        ->get('/tim')
        ->assertForbidden();
})->with([Peran::StafCs, Peran::PjKaryawan]);

test('the Tim & akses menu item is only offered to supervisors', function () {
    $this->actingAs(User::factory()->create())
        ->get('/katalog')
        ->assertInertia(fn (Assert $page) => $page->where('panel.akses.tim', false));

    $this->actingAs(User::factory()->supervisor()->create())
        ->get('/katalog')
        ->assertInertia(fn (Assert $page) => $page->where('panel.akses.tim', true));
});

test('staff show their panel access and last login', function () {
    $falah = User::factory()->supervisor()->create([
        'name' => 'Falah', 'nomor_wa' => '6281234560007',
        'terakhir_masuk_at' => now()->setTime(13, 40), 'terakhir_masuk_perangkat' => 'Chrome · Windows',
    ]);
    User::factory()->create([
        'name' => 'Bu Sarah', 'nomor_wa' => '6281298760646', 'peran' => Peran::PjKaryawan,
        'diundang_at' => now()->subDay(),
    ]);
    User::factory()->create([
        'name' => 'Rizky', 'nomor_wa' => '6282112340052', 'nonaktif_at' => now()->subDays(8),
        'terakhir_masuk_at' => '2026-09-12 16:00', 'terakhir_masuk_perangkat' => 'Chrome · Android',
    ]);

    $this->actingAs($falah)
        ->get('/tim')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tim')
            ->has('staf', 3)
            ->where('staf.0.nomor', '0812-••••-••07')
            ->where('staf.0.akses', ['label' => 'Aktif', 'nada' => 'green'])
            ->where('staf.0.terakhirMasuk', 'Hari ini 13:40 · Chrome, Windows')
            ->where('staf.1.inisial', 'S')
            ->where('staf.1.nomor', '0812-••••-0646')
            ->where('staf.1.peran', 'PJ karyawan')
            ->where('staf.1.akses', ['label' => 'Undangan terkirim', 'nada' => 'amber'])
            ->where('staf.1.terakhirMasuk', 'Belum pernah masuk')
            ->where('staf.2.akses', ['label' => 'Nonaktif', 'nada' => 'gray'])
            ->where('staf.2.terakhirMasuk', '12 Sep · Chrome, Android'));
});

test('the access table ticks the roles allowed for each action', function () {
    $this->actingAs(User::factory()->supervisor()->create())
        ->get('/tim')
        ->assertInertia(fn (Assert $page) => $page
            ->has('hakAkses', 11)
            ->where('hakAkses.4', [
                'aksi' => 'order_karyawan',
                'label' => 'Order karyawan',
                'peran' => ['SUPERVISOR' => true, 'STAF_CS' => false, 'PJ_KARYAWAN' => true],
            ]));
});

test('open sessions list this session first, one row per device, then WhatsApp admin use', function () {
    config(['session.driver' => 'database']);

    $falah = User::factory()->supervisor()->create(['name' => 'Falah', 'cabang' => 'Malang', 'nomor_wa' => '6281234560007']);
    $dina = User::factory()->create(['name' => 'Dina', 'cabang' => 'Malang']);

    $ini = SesiPanel::buat($falah, UserAgent::WINDOWS, '-2 minutes');
    SesiPanel::buat($falah, UserAgent::WINDOWS, '-1 hour');
    SesiPanel::buat($dina, UserAgent::ANDROID, '-5 hours');
    SesiPanel::buat($dina, UserAgent::FIREFOX, '-13 hours');
    ActivityLog::factory()->oleh($falah, SumberAktivitas::WhatsApp)->kategori(KategoriAktivitas::Jeda)
        ->create(['waktu' => now()->subHours(3), 'aksi' => 'JEDA customer']);

    $this->actingAs($falah)
        ->withCookie(config('session.cookie'), $ini)
        ->get('/tim')
        ->assertInertia(fn (Assert $page) => $page
            ->has('sesi', 3)
            ->where('sesi.0.judul', 'Falah · Chrome · Windows · Malang')
            ->where('sesi.0.keterangan', 'Sesi ini')
            ->where('sesi.0.ini', true)
            ->where('sesi.1.judul', 'Falah · WhatsApp 0812-••••-••07')
            ->where('sesi.1.keterangan', 'Perintah admin')
            ->where('sesi.2.judul', 'Dina · Chrome · Android · Malang')
            ->where('sesi.2.keterangan', '5 jam lalu')
            ->where('sesi.2.perangkat', 'Chrome · Android'));
});

test('Keluarkan ends one staff member\'s sessions on one device', function () {
    $dina = User::factory()->create();
    SesiPanel::buat($dina, UserAgent::ANDROID, '-5 hours');
    SesiPanel::buat($dina, UserAgent::ANDROID, '-1 hour');
    SesiPanel::buat($dina, UserAgent::FIREFOX, '-1 hour');

    $this->actingAs(User::factory()->supervisor()->create())
        ->delete("/tim/{$dina->id}/sesi", ['perangkat' => 'Chrome · Android'])
        ->assertRedirect();

    expect(DB::table('sessions')->where('user_id', $dina->id)->pluck('user_agent')->all())->toBe([UserAgent::FIREFOX]);
});

test('security settings are saved and Keluar otomatis sets the session lifetime', function () {
    $supervisor = User::factory()->supervisor()->create();

    $this->actingAs($supervisor)
        ->patch('/tim/keamanan', ['kode_perangkat_baru' => false])
        ->assertRedirect();
    $this->actingAs($supervisor)
        ->patch('/tim/keamanan', ['keluar_otomatis' => 4])
        ->assertRedirect();

    expect(ConfigToko::query()->where('kunci', PengaturanKeamanan::KODE_PERANGKAT_BARU)->value('nilai'))->toBe('0')
        ->and(PengaturanKeamanan::keluarOtomatisJam())->toBe(4);

    $this->get('/masuk');
    expect(config('session.lifetime'))->toBe(240);

    $this->actingAs($supervisor)
        ->get('/tim')
        ->assertInertia(fn (Assert $page) => $page
            ->where('keamanan.kodePerangkatBaru', false)
            ->where('keamanan.keluarOtomatis', 4)
            ->where('keamanan.pilihan', [12, 4, 24]));
});

test('Keluar otomatis only accepts the listed choices', function () {
    $this->actingAs(User::factory()->supervisor()->create())
        ->patch('/tim/keamanan', ['keluar_otomatis' => 5])
        ->assertSessionHasErrors('keluar_otomatis');
});

test('logging in records the time and device for Terakhir masuk', function () {
    // the new-device code has its own tests (PerangkatBaruTest)
    ConfigToko::create(['kunci' => 'PANEL_KODE_PERANGKAT_BARU', 'nilai' => '0']);
    $user = User::factory()->create();

    $this->withHeader('User-Agent', UserAgent::WINDOWS)
        ->post('/masuk', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/');

    $user->refresh();
    expect($user->terakhir_masuk_at?->equalTo(now()))->toBeTrue()
        ->and($user->terakhir_masuk_perangkat)->toBe('Chrome · Windows');
});

test('the demo shortcut leaves Terakhir masuk as in the design', function () {
    config(['app.demo' => true]);
    $falah = User::factory()->supervisor()->create(['email' => 'falah@bangbana.id', 'terakhir_masuk_at' => '2026-09-28 13:40']);

    $this->get('/_demo/login')->assertRedirect('/');

    expect($falah->refresh()->terakhir_masuk_at?->format('H:i'))->toBe('13:40');
});
