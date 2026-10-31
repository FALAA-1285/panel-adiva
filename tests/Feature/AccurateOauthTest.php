<?php

use App\Accurate\ApiAccurate;
use App\Accurate\BacaAccurate;
use App\Accurate\OauthAccurate;
use App\Accurate\SinkronBarangToko;
use App\Accurate\TiruanAccurate;
use App\Enums\JenisNotifikasi;
use App\Models\ActivityLog;
use App\Models\AksesAccurate;
use App\Models\BarangToko;
use App\Models\Notifikasi;
use App\Models\SinkronAccurate;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * "Sambungkan Accurate": OAuth with the app's Client ID and Secret, the tokens kept encrypted,
 * renewed before their 15 days run out, and the Bana Food database opened for host and session.
 */

beforeEach(function () {
    config([
        'services.accurate.client_id' => 'id-aplikasi-uji',
        'services.accurate.client_secret' => 'rahasia-aplikasi-uji',
        'services.accurate.redirect' => 'https://panel-adiva.vercel.app/accurate/kembali',
        'services.accurate.token' => null,
        'services.accurate.db_id' => '2743429',
    ]);
});

/**
 * @return array<string, mixed>
 */
function tokenAccurate(string $akses = 'akses-1', string $segar = 'segar-1'): array
{
    return ['access_token' => $akses, 'token_type' => 'bearer', 'refresh_token' => $segar, 'expires_in' => 1295999, 'scope' => 'item_view'];
}

function aksesTersimpan(array $isi = []): AksesAccurate
{
    return AksesAccurate::create([
        'access_token' => 'akses-1',
        'refresh_token' => 'segar-1',
        'kedaluwarsa_at' => now()->addDays(10),
        'host' => 'https://zeus.accurate.id',
        'session' => 'sesi-1',
        'session_at' => now(),
        'disambungkan_at' => now(),
        ...$isi,
    ]);
}

/**
 * @return array<string, mixed>
 */
function daftarBarangAccurate(): array
{
    return ['s' => true, 'd' => [
        ['id' => 1, 'no' => 'BMB-001', 'name' => 'Ketumbar Bubuk 100 gr', 'upcNo' => '8992001000011', 'unitPrice' => 6500, 'unit1' => ['name' => 'PCS'], 'itemCategory' => ['name' => 'BUMBU'], 'suspended' => false],
    ], 'sp' => ['page' => 1, 'pageCount' => 1]];
}

function mulaiSambungan(): string
{
    $falah = User::query()->where('email', 'falah@bangbana.id')->first() ?? User::factory()->falah()->create();
    $tujuan = test()->actingAs($falah)->get('/accurate/sambungkan')->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url((string) $tujuan, PHP_URL_QUERY), $kueri);

    return (string) $kueri['state'];
}

test('Sambungkan Accurate sends the supervisor to Accurate asking only to view items', function () {
    $falah = User::factory()->falah()->create();

    $tujuan = $this->actingAs($falah)->get('/accurate/sambungkan')->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url((string) $tujuan, PHP_URL_QUERY), $kueri);

    expect($tujuan)->toStartWith('https://account.accurate.id/oauth/authorize?')
        ->and($kueri)->toMatchArray([
            'client_id' => 'id-aplikasi-uji',
            'response_type' => 'code',
            'redirect_uri' => 'https://panel-adiva.vercel.app/accurate/kembali',
            'scope' => 'item_view',
        ])
        ->and($kueri['state'])->toHaveLength(40);
});

test('staff cannot connect, disconnect or come back from Accurate', function () {
    $staf = User::factory()->create();

    $this->actingAs($staf)->get('/accurate/sambungkan')->assertForbidden();
    $this->actingAs($staf)->get('/accurate/kembali?code=x')->assertForbidden();
    $this->actingAs($staf)->post('/accurate/putuskan')->assertForbidden();
});

test('coming back stores the access encrypted, opens Bana Food, logs it and starts the first sync', function () {
    Http::fake([
        'account.accurate.id/oauth/token' => Http::response(tokenAccurate()),
        'account.accurate.id/api/open-db.do*' => Http::response(['s' => true, 'host' => 'https://zeus.accurate.id/', 'session' => 'sesi-1']),
        'zeus.accurate.id/*' => Http::response(daftarBarangAccurate()),
    ]);
    $state = mulaiSambungan();

    $this->get('/accurate/kembali?code=kode-1&state='.$state)
        ->assertRedirect('/katalog/toko')
        ->assertSessionHas('inertia.flash_data.toast', 'Accurate tersambung. Barang toko sedang diambil.');

    $akses = AksesAccurate::query()->sole();
    expect($akses->access_token)->toBe('akses-1')
        ->and($akses->refresh_token)->toBe('segar-1')
        ->and($akses->host)->toBe('https://zeus.accurate.id')
        ->and($akses->session)->toBe('sesi-1')
        ->and($akses->kedaluwarsa_at->greaterThan(now()->addDays(14)))->toBeTrue()
        // nothing readable at rest
        ->and(DB::table('akses_accurate')->value('access_token'))->not->toContain('akses-1')
        ->and(ActivityLog::query()->where('aksi', 'Sambungkan Accurate')->exists())->toBeTrue()
        // the queue runs at once in the tests: the first sync went through OAuth
        ->and(BarangToko::query()->pluck('nama')->all())->toBe(['Ketumbar Bubuk 100 gr']);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://account.accurate.id/oauth/token'
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('id-aplikasi-uji:rahasia-aplikasi-uji'))
        && $r['grant_type'] === 'authorization_code' && $r['code'] === 'kode-1'
        && $r['redirect_uri'] === 'https://panel-adiva.vercel.app/accurate/kembali');
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://zeus.accurate.id/accurate/api/item/list.do')
        && $r->hasHeader('Authorization', 'Bearer akses-1') && $r->hasHeader('X-Session-ID', 'sesi-1'));
});

test('a wrong state, a refused access or an account without Bana Food is not stored', function () {
    Http::fake([
        'account.accurate.id/oauth/token' => Http::response(tokenAccurate()),
        'account.accurate.id/api/open-db.do*' => Http::response(['s' => false, 'd' => ['Database tidak ditemukan']], 200),
    ]);

    mulaiSambungan();
    $this->get('/accurate/kembali?code=kode-1&state=palsu')->assertSessionHasErrors(['accurate' => 'Sambungan tidak dikenali atau sudah lewat. Tekan Sambungkan Accurate sekali lagi.']);

    $state = mulaiSambungan();
    $this->get('/accurate/kembali?error=access_denied&state='.$state)->assertSessionHasErrors('accurate');

    $state = mulaiSambungan();
    $this->get('/accurate/kembali?code=kode-1&state='.$state)
        ->assertSessionHasErrors(['accurate' => 'Accurate belum tersambung: Database Accurate 2743429 tidak bisa dibuka dengan akun ini: HTTP 200 · Database tidak ditemukan']);

    expect(AksesAccurate::query()->count())->toBe(0);
});

test('a callback nobody started is refused', function () {
    $this->actingAs(User::factory()->falah()->create())
        ->get('/accurate/kembali?code=kode-1')
        ->assertSessionHasErrors('accurate');

    expect(AksesAccurate::query()->count())->toBe(0);
});

test('a token near its end is renewed before reading, keeping the refresh token when none comes back', function () {
    aksesTersimpan(['kedaluwarsa_at' => now()->addDay()]);
    Http::fake([
        'account.accurate.id/oauth/token' => Http::response(['access_token' => 'akses-2', 'expires_in' => 1295999]),
        'account.accurate.id/api/open-db.do*' => Http::response(['s' => true, 'host' => 'https://zeus.accurate.id', 'session' => 'sesi-2']),
        'zeus.accurate.id/*' => Http::response(daftarBarangAccurate()),
    ]);

    expect(app(ApiAccurate::class)->barang())->toHaveCount(1);

    $akses = AksesAccurate::query()->sole();
    expect($akses->access_token)->toBe('akses-2')
        ->and($akses->refresh_token)->toBe('segar-1')
        ->and($akses->session)->toBe('sesi-2');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://account.accurate.id/oauth/token' && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'segar-1');
});

test('a session Accurate closed is opened again once', function () {
    aksesTersimpan();
    Http::fake([
        'account.accurate.id/api/open-db.do*' => Http::response(['s' => true, 'host' => 'https://zeus.accurate.id', 'session' => 'sesi-baru']),
        'zeus.accurate.id/*' => Http::sequence()
            ->push(['s' => false, 'd' => ['Session expired']], 401)
            ->push(daftarBarangAccurate()),
    ]);

    expect(app(ApiAccurate::class)->barang())->toHaveCount(1)
        ->and(AksesAccurate::query()->sole()->session)->toBe('sesi-baru');
});

test('a refresh Accurate refuses asks to connect again, tells the supervisor once the goods are stale, and hides the tokens', function () {
    accuratePalsuOauth();
    aksesTersimpan(['kedaluwarsa_at' => now()->subHour()]);
    SinkronAccurate::create(['mulai' => now()->subHours(4), 'status' => 'BERHASIL', 'jumlah' => 1]);
    Http::fake(['account.accurate.id/oauth/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token segar-1 expired'], 400)]);

    $hasil = app(SinkronBarangToko::class)->jalankan();

    expect($hasil->status)->toBe('GAGAL')
        ->and($hasil->galat)->toBe('Sambungan Accurate perlu disambungkan ulang (Accurate menolak: HTTP 400 · Refresh token *** expired).')
        ->and(Notifikasi::query()->sole()->jenis)->toBe(JenisNotifikasi::SinkronAccurate->value);
});

test('OAuth comes before an API Token, and the stand-in is only for a demo with neither', function () {
    expect(app(BacaAccurate::class))->toBeInstanceOf(ApiAccurate::class);

    config(['services.accurate.client_id' => null, 'services.accurate.client_secret' => null]);
    expect(app(BacaAccurate::class))->toBeInstanceOf(TiruanAccurate::class);

    aksesTersimpan();
    config(['services.accurate.token' => 'token-lama', 'services.accurate.signature_secret' => 'rahasia-lama']);
    Http::fake(['zeus.accurate.id/*' => Http::response(daftarBarangAccurate())]);

    expect((new ApiAccurate(new OauthAccurate))->barang())->toHaveCount(1);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api-token.do'));
});

test('Barang toko shows how Accurate is connected without any token, and Putuskan removes the access', function () {
    $falah = User::factory()->falah()->create();
    aksesTersimpan(['disambungkan_oleh_id' => $falah->id]);

    $this->actingAs($falah)->get('/katalog/toko')
        ->assertInertia(fn (Assert $page) => $page
            ->where('akses.cara', 'oauth')
            ->where('akses.oleh', 'Falah')
            ->where('akses.bisaOauth', true)
            ->whereNot('akses.berlaku', null))
        ->assertDontSee('akses-1')
        ->assertDontSee('segar-1');

    $this->actingAs($falah)->post('/accurate/putuskan')->assertRedirect('/katalog/toko');

    expect(AksesAccurate::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('aksi', 'Putuskan Accurate')->exists())->toBeTrue();
});

/**
 * The real Accurate reader, whatever the demo binding would pick.
 */
function accuratePalsuOauth(): void
{
    app()->bind(BacaAccurate::class, fn () => new ApiAccurate(new OauthAccurate));
}
