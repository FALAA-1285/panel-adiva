<?php

use App\Accurate\AccurateGagal;
use App\Accurate\ApiAccurate;
use App\Accurate\BacaAccurate;
use App\Accurate\SinkronBarangToko;
use App\Accurate\TiruanAccurate;
use App\Enums\JenisNotifikasi;
use App\Models\ActivityLog;
use App\Models\BarangToko;
use App\Models\Notifikasi;
use App\Models\SinkronAccurate;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'services.accurate.token' => 'aat.token-uji',
        'services.accurate.signature_secret' => 'rahasia-uji',
        'services.accurate.db_id' => '2743429',
    ]);
});

/**
 * @param  list<array<string, mixed>>  $d
 * @return array<string, mixed>
 */
function halamanAccurate(array $d, int $halaman, int $jumlahHalaman): array
{
    return ['s' => true, 'd' => $d, 'sp' => ['page' => $halaman, 'pageSize' => 100, 'pageCount' => $jumlahHalaman, 'rowCount' => 3]];
}

/**
 * A stand-in that returns what the test hands it, or fails.
 *
 * @param  list<array<string, mixed>>|AccurateGagal  $hasil
 */
function accuratePalsu(array|AccurateGagal $hasil): void
{
    app()->instance(BacaAccurate::class, new class($hasil) implements BacaAccurate
    {
        /** @param  list<array<string, mixed>>|AccurateGagal  $hasil */
        public function __construct(private array|AccurateGagal $hasil) {}

        public function barang(): array
        {
            if ($this->hasil instanceof AccurateGagal) {
                throw $this->hasil;
            }

            /** @var list<array{accurate_id: int, kode: string|null, nama: string, barcode: string|null, kategori: string|null, satuan: string|null, harga: int|null, nonaktif: bool}> */
            return $this->hasil;
        }
    });
}

/**
 * @return array{accurate_id: int, kode: string|null, nama: string, barcode: string|null, kategori: string|null, satuan: string|null, harga: int|null, nonaktif: bool}
 */
function barangAccurate(int $id, ?string $barcode = '899', bool $nonaktif = false, int $harga = 10000): array
{
    return ['accurate_id' => $id, 'kode' => "K-{$id}", 'nama' => "Barang {$id}", 'barcode' => $barcode, 'kategori' => 'BUMBU', 'satuan' => 'PCS', 'harga' => $harga, 'nonaktif' => $nonaktif];
}

test('the API reads every page of the item list with a signed request and only reads', function () {
    Http::fake([
        'account.accurate.id/api/api-token.do' => Http::response(['s' => true, 'd' => ['database' => ['id' => 2743429, 'host' => 'https://zeus.accurate.id/']]]),
        'zeus.accurate.id/accurate/api/item/list.do*' => Http::sequence()
            ->push(halamanAccurate([
                ['id' => 1, 'no' => 'BMB-001', 'name' => 'Ketumbar Bubuk 100 gr', 'upcNo' => ' 8992001000011 ', 'unitPrice' => 6500.0, 'unit1' => ['name' => 'PCS'], 'itemCategory' => ['name' => 'BUMBU'], 'suspended' => false],
                ['id' => 2, 'no' => 'PKG-001', 'name' => 'Plastik', 'upcNo' => '', 'unitPrice' => 0, 'suspended' => false],
            ], 1, 2))
            ->push(halamanAccurate([
                ['id' => 3, 'no' => 'BMB-099', 'name' => 'Bumbu Keju Lama', 'upcNo' => '8992001000998', 'unitPrice' => 15000, 'unit1' => 'PCS', 'itemCategory' => 'BUMBU', 'suspended' => true],
            ], 2, 2)),
    ]);

    $barang = (new ApiAccurate)->barang();

    expect($barang)->toHaveCount(3)
        ->and($barang[0])->toBe(['accurate_id' => 1, 'kode' => 'BMB-001', 'nama' => 'Ketumbar Bubuk 100 gr', 'barcode' => '8992001000011', 'kategori' => 'BUMBU', 'satuan' => 'PCS', 'harga' => 6500, 'nonaktif' => false])
        ->and($barang[1]['barcode'])->toBeNull()
        ->and($barang[1]['harga'])->toBeNull()
        ->and($barang[2]['nonaktif'])->toBeTrue()
        ->and($barang[2]['satuan'])->toBe('PCS');

    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    Http::assertSent(function (Request $r) {
        $waktu = $r->header('X-Api-Timestamp')[0] ?? '';

        return $r->hasHeader('Authorization', 'Bearer aat.token-uji')
            && ctype_digit($waktu)
            && $r->header('X-Api-Signature')[0] === hash_hmac('sha256', $waktu, 'rahasia-uji');
    });
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://zeus.accurate.id/accurate/api/item/list.do') && $r['sp.page'] === 2);
});

test('the API says what is wrong without a token, or when Accurate refuses', function () {
    config(['services.accurate.token' => '']);

    expect(fn () => (new ApiAccurate)->barang())->toThrow(AccurateGagal::class, 'ACCURATE_API_TOKEN dan ACCURATE_SIGNATURE_SECRET belum diisi.');

    config(['services.accurate.token' => 'aat.token-uji']);
    Http::fake(['account.accurate.id/*' => Http::response(['s' => false, 'd' => ['Token tidak valid']], 401)]);

    expect(fn () => (new ApiAccurate)->barang())->toThrow(AccurateGagal::class, 'Accurate menolak permintaan: HTTP 401 · Token tidak valid');
});

test('the API refuses a token without the Bana Food database', function () {
    Http::fake(['account.accurate.id/*' => Http::response(['s' => true, 'd' => ['database' => [['id' => 1, 'host' => 'https://lain.accurate.id']]]])]);

    expect(fn () => (new ApiAccurate)->barang())->toThrow(AccurateGagal::class, 'Database Accurate 2743429 tidak ada di API Token ini.');
});

test('only goods with a barcode that are not suspended reach the shop shelf', function () {
    $this->artisan('accurate:sinkron')->expectsOutput('15 barang toko tersimpan')->assertSuccessful();

    expect(BarangToko::query()->count())->toBe(15)
        ->and(BarangToko::query()->where('nama', 'Ketumbar Bubuk 100 gr')->sole()->only(['kode', 'barcode', 'kategori', 'satuan', 'harga']))
        ->toBe(['kode' => 'BMB-001', 'barcode' => '8992001000011', 'kategori' => 'BUMBU', 'satuan' => 'PCS', 'harga' => 6500])
        ->and(BarangToko::query()->whereIn('accurate_id', [5901, 5902, 5903])->exists())->toBeFalse()
        ->and(SinkronAccurate::query()->sole()->only(['status', 'jumlah']))->toBe(['status' => 'BERHASIL', 'jumlah' => 15]);
});

test('a run updates prices and removes goods that left the shop', function () {
    accuratePalsu([barangAccurate(1), barangAccurate(2), barangAccurate(3)]);
    app(SinkronBarangToko::class)->jalankan();

    // 1 has a new price, 2 lost its barcode, 3 was suspended, 4 is new
    accuratePalsu([barangAccurate(1, harga: 12500), barangAccurate(2, barcode: null), barangAccurate(3, nonaktif: true), barangAccurate(4)]);
    app(SinkronBarangToko::class)->jalankan();

    expect(BarangToko::query()->orderBy('accurate_id')->pluck('harga', 'accurate_id')->all())->toBe([1 => 12500, 4 => 10000]);
});

test('a failed run changes nothing and is kept with its reason', function () {
    accuratePalsu([barangAccurate(1), barangAccurate(2)]);
    app(SinkronBarangToko::class)->jalankan();

    accuratePalsu(new AccurateGagal('Accurate tidak bisa dihubungi (timeout).'));
    $this->artisan('accurate:sinkron')->expectsOutput('Gagal: Accurate tidak bisa dihubungi (timeout).')->assertFailed();

    expect(BarangToko::query()->count())->toBe(2)
        ->and(SinkronAccurate::query()->latest('id')->first()->only(['status', 'galat']))
        ->toBe(['status' => 'GAGAL', 'galat' => 'Accurate tidak bisa dihubungi (timeout).']);
});

test('an empty answer never wipes a filled shelf', function () {
    accuratePalsu([barangAccurate(1), barangAccurate(2)]);
    app(SinkronBarangToko::class)->jalankan();

    accuratePalsu([barangAccurate(3, barcode: null)]);
    $hasil = app(SinkronBarangToko::class)->jalankan();

    expect($hasil->status)->toBe('GAGAL')
        ->and($hasil->galat)->toContain('data lama dipertahankan')
        ->and(BarangToko::query()->count())->toBe(2);
});

test('the supervisor hears once when the shelf has not been refreshed for three hours', function () {
    accuratePalsu([barangAccurate(1)]);
    app(SinkronBarangToko::class)->jalankan();

    accuratePalsu(new AccurateGagal('Accurate menolak permintaan: HTTP 401'));
    $this->travel(2)->hours();
    app(SinkronBarangToko::class)->jalankan();
    expect(Notifikasi::query()->count())->toBe(0);

    $this->travel(2)->hours();
    app(SinkronBarangToko::class)->jalankan();
    $this->travel(1)->hours();
    app(SinkronBarangToko::class)->jalankan();

    expect(Notifikasi::query()->sole()->only(['jenis', 'judul']))
        ->toBe(['jenis' => JenisNotifikasi::SinkronAccurate->value, 'judul' => 'Data barang toko dari Accurate belum terbarui']);
});

test('--coba shows what Accurate sent and saves nothing', function () {
    $this->artisan('accurate:sinkron', ['--coba' => true])
        ->expectsOutputToContain('18 barang di Accurate · 15 dijual di toko')
        ->assertSuccessful();

    expect(BarangToko::query()->count())->toBe(0)
        ->and(SinkronAccurate::query()->count())->toBe(0);
});

test('demo mode reads the stand-in, and the run is scheduled hourly outside demo mode', function () {
    expect(app(BacaAccurate::class))->toBeInstanceOf(TiruanAccurate::class);

    $jadwal = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'accurate:sinkron'));

    expect($jadwal)->not->toBeNull()
        ->and($jadwal->expression)->toBe('0 * * * *');
});

test('Katalog → Barang toko lists the shelf, searches, and says when the last run failed', function () {
    app(SinkronBarangToko::class)->jalankan();
    accuratePalsu(new AccurateGagal('Accurate menolak permintaan: HTTP 401'));
    app(SinkronBarangToko::class)->jalankan();

    $this->actingAs(User::factory()->create())
        ->get('/katalog/toko')
        ->assertInertia(fn (Assert $page) => $page
            ->component('katalog/toko')
            ->has('barang', 15)
            ->where('barang.0', fn ($b) => collect($b)->except('id')->all() === [
                'nama' => 'Basreng Pedas Daun Jeruk 200 gr', 'kode' => 'BB-001', 'barcode' => '8997002000012',
                'kategori' => 'BANG BANA', 'satuan' => 'PCS', 'harga' => 'Rp16.000',
            ])
            ->where('halaman', ['sekarang' => 1, 'total' => 15, 'per' => 50])
            ->where('kategori', ['BANG BANA', 'BUMBU', 'KILOAN', 'MINUMAN'])
            ->where('tab.toko', 15)
            ->where('sinkron.jumlah', 15)
            ->where('sinkron.gagal.galat', 'Accurate menolak permintaan: HTTP 401')
            ->whereNot('sinkron.berhasil', null));

    $this->actingAs(User::factory()->create())
        ->get('/katalog/toko?cari=bubuk+ketumbar')
        ->assertInertia(fn (Assert $page) => $page->has('barang', 1)->where('barang.0.nama', 'Ketumbar Bubuk 100 gr'));

    $this->actingAs(User::factory()->create())
        ->get('/katalog/toko?cari=2000000510105')
        ->assertInertia(fn (Assert $page) => $page->has('barang', 1)->where('barang.0.nama', 'Tepung Tapioka Kiloan'));

    $this->actingAs(User::factory()->create())
        ->get('/katalog/toko?kategori=KILOAN')
        ->assertInertia(fn (Assert $page) => $page->has('barang', 4)->where('filter.kategori', 'KILOAN'));
});

test('only a supervisor can start a run by hand, and it is logged', function () {
    $this->actingAs(User::factory()->create())
        ->post('/katalog/toko/sinkron')
        ->assertForbidden();

    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)->from('/katalog/toko')
        ->post('/katalog/toko/sinkron')
        ->assertRedirect('/katalog/toko');

    expect(BarangToko::query()->count())->toBe(15)
        ->and(SinkronAccurate::query()->sole()->oleh_id)->toBe($falah->id)
        ->and(ActivityLog::query()->sole()->aksi)->toBe('Sinkron barang toko');
});
