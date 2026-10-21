<?php

use App\Bot\SesiWaha;
use App\Bot\WahaSesi;
use App\Enums\Peran;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\User;
use App\Models\WorkflowEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    ConfigToko::create(['kunci' => 'DAFTAR_NOMOR_BOT', 'nilai' => 'bangbana-waha=Nomor Utama=utama,bangbana-2=Nomor Lawang=CS 2']);
    ConfigToko::create(['kunci' => 'SESI_UTAMA', 'nilai' => 'bangbana-waha']);
    config(['services.waha.url' => 'https://waha.test', 'services.waha.key' => 'kunci']);
    $this->app->bind(SesiWaha::class, WahaSesi::class);
    $this->falah = User::factory()->falah()->create();
});

/**
 * Pantau Koneksi's last check of a number.
 */
function koneksi(string $sesi, string $status): void
{
    WorkflowEvent::factory()->create(['jenis' => 'KONEKSI', 'status' => $status, 'data' => ['sesi' => $sesi]]);
}

test('Lihat QR shows the QR of the number that is down', function () {
    koneksi('bangbana-waha', 'TERHUBUNG');
    koneksi('bangbana-2', 'PUTUS');
    Http::fake([
        'waha.test/api/sessions/bangbana-2' => Http::response(['name' => 'bangbana-2', 'status' => 'SCAN_QR_CODE']),
        'waha.test/api/bangbana-2/auth/qr*' => Http::response('PNG-QR', 200, ['Content-Type' => 'image/png']),
    ]);

    $this->actingAs($this->falah)->get('/monitoring/waha/qr')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/bangbana-2/auth/qr') && $r->header('X-Api-Key')[0] === 'kunci');
});

test('a connected number needs no QR', function () {
    koneksi('bangbana-waha', 'TERHUBUNG');
    Http::fake(['waha.test/api/sessions/bangbana-waha' => Http::response(['name' => 'bangbana-waha', 'status' => 'WORKING'])]);

    $this->actingAs($this->falah)->get('/monitoring/waha/qr')
        ->assertOk()
        ->assertSee('Nomor Utama sudah terhubung — tidak perlu memindai QR.');
});

test('WAHA not answering is said plainly', function () {
    Http::fake(['waha.test/*' => Http::response('Unauthorized', 401)]);

    $this->actingAs($this->falah)->get('/monitoring/waha/qr')
        ->assertStatus(502)
        ->assertSee('QR Nomor Utama tidak bisa diambil: WAHA menolak (401): Unauthorized');
});

test('Restart sesi restarts the number that is down and is logged', function () {
    koneksi('bangbana-2', 'PUTUS');
    Http::fake(['waha.test/api/sessions/bangbana-2/restart' => Http::response(['status' => 'STARTING'])]);

    $this->actingAs($this->falah)->post('/monitoring/waha/restart')->assertRedirect()->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://waha.test/api/sessions/bangbana-2/restart');
    expect(ActivityLog::query()->sole()->only(['aksi', 'detail']))->toBe(['aksi' => 'Restart sesi WAHA', 'detail' => 'Nomor Lawang (bangbana-2)']);
});

test('a restart WAHA refuses shows why', function () {
    Http::fake(['waha.test/*' => Http::response('Session not found', 404)]);

    $this->actingAs($this->falah)->post('/monitoring/waha/restart')
        ->assertSessionHasErrors(['waha' => 'Restart Nomor Utama gagal: WAHA menolak (404): Session not found']);
    expect(ActivityLog::query()->count())->toBe(0);
});

test('only supervisors may show the QR or restart', function () {
    $cs = User::factory()->create(['peran' => Peran::StafCs]);
    Http::fake();

    $this->actingAs($cs)->get('/monitoring/waha/qr')->assertForbidden();
    $this->actingAs($cs)->post('/monitoring/waha/restart')->assertForbidden();
    Http::assertNothingSent();
});
