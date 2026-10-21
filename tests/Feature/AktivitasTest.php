<?php

use App\Enums\KategoriAktivitas;
use App\Enums\SumberAktivitas;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\User;
use App\Support\PerangkatDikenal;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\UserAgent;

test('guests are sent to the login page', function () {
    $this->get('/aktivitas')->assertRedirect('/masuk');
});

test('lines are grouped per day for the last seven days, newest first', function () {
    foreach ([
        '2026-09-28 13:15' => 'Cek batas bayar',
        '2026-09-28 13:21' => 'Balasan gagal',
        '2026-09-27 10:00' => 'Pengingat jeda',
        '2026-09-25 09:00' => 'Nomor bot tersambung lagi',
        '2026-09-21 09:00' => 'Terlalu lama',
    ] as $waktu => $aksi) {
        ActivityLog::factory()->pada($waktu)->create(['aksi' => $aksi]);
    }

    $this->actingAs(User::factory()->create())
        ->get('/aktivitas')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('aktivitas')
            ->has('hari', 3)
            ->where('hari.0.judul', 'Hari ini · 28 Sep')
            ->has('hari.0.kejadian', 2)
            ->where('hari.0.kejadian.0.aksi', 'Balasan gagal')
            ->where('hari.0.kejadian.0.jam', '13:21')
            ->where('hari.0.kejadian.0.sumber', ['label' => 'Sistem', 'nada' => 'gray'])
            ->where('hari.1.judul', 'Kemarin · 27 Sep')
            ->where('hari.2.judul', 'Jumat · 25 Sep'));
});

test('today keeps its panel when nothing matches', function () {
    $this->actingAs(User::factory()->create())
        ->get('/aktivitas?q=tidak-ada')
        ->assertInertia(fn (Assert $page) => $page
            ->has('hari', 1)
            ->where('hari.0.judul', 'Hari ini · 28 Sep')
            ->has('hari.0.kejadian', 0)
            ->where('pilih', null));
});

test('the latest setting change that can still be put back is selected', function () {
    $dina = User::factory()->create(['name' => 'Dina']);
    ConfigToko::create(['kunci' => 'BATAS_BAYAR_JAM', 'nilai' => '5']);
    ActivityLog::factory()->oleh(User::factory()->create())->kategori(KategoriAktivitas::Pesanan)
        ->pada('2026-09-28 13:42')->create(['aksi' => 'ACC order']);
    $ubah = ActivityLog::factory()->oleh($dina)->ubahPengaturan('BATAS_BAYAR_JAM', '4', '5')->pada('2026-09-28 12:10')->create();

    $this->actingAs(User::factory()->supervisor()->create())
        ->get('/aktivitas')
        ->assertInertia(fn (Assert $page) => $page
            ->where('pilih', $ubah->id)
            ->where('hari.0.kejadian.1.rincian', [
                'judul' => 'Ubah pengaturan',
                'sub' => 'Batas bayar untuk order kirim',
                'oleh' => 'Dina · Staf CS',
                'waktu' => '28 Sep 2026, 12:10',
                'sumber' => 'Panel · Chrome, Android',
                'kunci' => 'BATAS_BAYAR_JAM',
                'sebelum' => '4 jam kerja',
                'sesudah' => '5 jam kerja',
                'dampak' => 'Langsung dipakai alur Batas Bayar di pengecekan berikutnya (tiap jam, menit ke-15), termasuk untuk order yang sedang menunggu bayar.',
                'kembalikan' => 'Kembalikan ke 4 jam kerja',
            ]));
});

test('only supervisors are offered Kembalikan, and only while the value is unchanged', function () {
    $dina = User::factory()->create(['name' => 'Dina']);
    ConfigToko::create(['kunci' => 'BATAS_BAYAR_JAM', 'nilai' => '5']);
    ActivityLog::factory()->oleh($dina)->ubahPengaturan('BATAS_BAYAR_JAM', '4', '5')->create();

    $this->actingAs($dina)
        ->get('/aktivitas')
        ->assertInertia(fn (Assert $page) => $page->where('hari.0.kejadian.0.rincian.kembalikan', null));

    ConfigToko::query()->where('kunci', 'BATAS_BAYAR_JAM')->update(['nilai' => '6']);

    $this->actingAs(User::factory()->supervisor()->create())
        ->get('/aktivitas')
        ->assertInertia(fn (Assert $page) => $page->where('hari.0.kejadian.0.rincian.kembalikan', null));
});

test('Kembalikan puts the setting back and logs the change', function () {
    $falah = User::factory()->falah()->create();
    ConfigToko::create(['kunci' => 'BATAS_BAYAR_JAM', 'nilai' => '5']);
    $ubah = ActivityLog::factory()->oleh(User::factory()->create())->ubahPengaturan('BATAS_BAYAR_JAM', '4', '5')->create();

    $this->actingAs($falah)
        ->withHeader('User-Agent', UserAgent::WINDOWS)
        ->post("/aktivitas/{$ubah->id}/kembalikan")
        ->assertRedirect();

    $baru = ActivityLog::query()->latest('id')->first();
    expect(ConfigToko::query()->where('kunci', 'BATAS_BAYAR_JAM')->value('nilai'))->toBe('4')
        ->and($baru?->pelaku)->toBe('Falah')
        ->and($baru?->detail)->toBe('Batas bayar (kirim): 5 → 4 jam kerja')
        ->and($baru?->data)->toBe(['kunci' => 'BATAS_BAYAR_JAM', 'sebelum' => '5', 'sesudah' => '4', 'perangkat' => 'Chrome · Windows']);

    // the value has changed since, so the old line can no longer be put back
    $this->actingAs($falah)->post("/aktivitas/{$ubah->id}/kembalikan")->assertConflict();
});

test('staff other than supervisors may not put settings back', function () {
    ConfigToko::create(['kunci' => 'BATAS_BAYAR_JAM', 'nilai' => '5']);
    $ubah = ActivityLog::factory()->oleh(User::factory()->create())->ubahPengaturan('BATAS_BAYAR_JAM', '4', '5')->create();

    $this->actingAs(User::factory()->create())
        ->post("/aktivitas/{$ubah->id}/kembalikan")
        ->assertForbidden();
});

test('filters narrow the lines and unknown values are ignored', function (string $query, array $aksi) {
    $dina = User::factory()->create(['name' => 'Dina']);
    ActivityLog::factory()->pada('2026-09-28 13:00')->create(['aksi' => 'Cek batas bayar']);
    ActivityLog::factory()->adiva()->kategori(KategoriAktivitas::Percakapan)->pada('2026-09-28 12:48')
        ->create(['aksi' => 'Teruskan ke admin', 'detail' => 'Pak Hendra · nego harga']);
    ActivityLog::factory()->oleh($dina, SumberAktivitas::WhatsApp)->kategori(KategoriAktivitas::Jeda)->pada('2026-09-28 10:05')
        ->create(['aksi' => 'JEDA customer']);

    $query = str_replace('{dina}', (string) $dina->id, $query);

    $this->actingAs($dina)
        ->get('/aktivitas?'.$query)
        ->assertInertia(fn (Assert $page) => $page->where('hari.0.kejadian', fn ($kejadian) => collect($kejadian)->pluck('aksi')->all() === $aksi));
})->with([
    'search in detail' => ['q=hendra', ['Teruskan ke admin']],
    'by staff' => ['oleh={dina}', ['JEDA customer']],
    'by Adiva' => ['oleh=adiva', ['Teruskan ke admin']],
    'by Sistem' => ['oleh=sistem', ['Cek batas bayar']],
    'by action' => ['kategori=JEDA', ['JEDA customer']],
    'by source' => ['sumber=WHATSAPP', ['JEDA customer']],
    // PERCAKAPAN exists but is not one of the "Semua aksi" choices
    'unknown values' => ['sumber=EMAIL&kategori=PERCAKAPAN', ['Cek batas bayar', 'Teruskan ke admin', 'JEDA customer']],
]);

test('Semua orang lists active staff, then Adiva and Sistem', function () {
    $falah = User::factory()->falah()->create();
    User::factory()->create(['name' => 'Rizky', 'nonaktif_at' => now()]);

    $this->actingAs($falah)
        ->get('/aktivitas')
        ->assertInertia(fn (Assert $page) => $page
            ->where('orang', fn ($orang) => collect($orang)->pluck('label')->all() === ['Falah', 'Adiva', 'Sistem'])
            ->where('kategori', fn ($kategori) => collect($kategori)->pluck('label')->all() === ['Pesanan', 'Jeda', 'Pengaturan', 'Otomasi', 'Login', 'Broadcast']));
});

test('Ekspor CSV holds every matching line, not only the last days', function () {
    ActivityLog::factory()->pada('2026-09-28 13:21')->create(['aksi' => 'Balasan gagal', 'detail' => 'Nisa Aulia · WAHA timeout']);
    ActivityLog::factory()->pada('2026-08-01 09:00')->create(['aksi' => 'Arsip data', 'detail' => '12 order diarsipkan']);
    ActivityLog::factory()->adiva()->pada('2026-09-28 12:48')->create(['aksi' => 'Teruskan ke admin']);

    $csv = $this->actingAs(User::factory()->create())
        ->get('/aktivitas/ekspor?sumber=SISTEM')
        ->assertOk()
        ->assertDownload('log-aktivitas-2026-09-28.csv')
        ->streamedContent();

    expect($csv)->toBe("\xEF\xBB\xBF".implode("\n", [
        'Waktu,Oleh,Aksi,Detail,Sumber',
        '"2026-09-28 13:21",Sistem,"Balasan gagal","Nisa Aulia · WAHA timeout",Sistem',
        '"2026-08-01 09:00",Sistem,"Arsip data","12 order diarsipkan",Sistem',
    ])."\n");
});

test('logging in and changing security settings are written to the log', function () {
    $user = User::factory()->falah()->create(['cabang' => 'Malang']);

    // a device that already entered a code (the new-device code has its own tests)
    $this->withCookie(PerangkatDikenal::COOKIE, (string) json_encode([$user->id]))
        ->withHeader('User-Agent', UserAgent::WINDOWS)->post('/masuk', ['email' => $user->email, 'password' => 'password']);
    $this->actingAs($user)->withHeader('User-Agent', UserAgent::WINDOWS)->patch('/tim/keamanan', ['keluar_otomatis' => 4, 'kode_perangkat_baru' => false]);

    expect(ActivityLog::query()->orderBy('id')->get(['aksi', 'kategori', 'detail'])->toArray())->toBe([
        ['aksi' => 'Masuk', 'kategori' => KategoriAktivitas::Login->value, 'detail' => 'Chrome · Windows · Malang'],
        ['aksi' => 'Ubah pengaturan', 'kategori' => KategoriAktivitas::Pengaturan->value, 'detail' => 'Kode WhatsApp di perangkat baru: aktif → nonaktif'],
        ['aksi' => 'Ubah pengaturan', 'kategori' => KategoriAktivitas::Pengaturan->value, 'detail' => 'Keluar otomatis: 12 → 4 jam'],
    ]);
});
