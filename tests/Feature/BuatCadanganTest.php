<?php

use App\Console\Commands\BuatCadangan;
use App\Enums\JenisNotifikasi;
use App\Models\Notifikasi;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'app.demo' => false,
        'cadangan.disk' => 'cadangan-uji',
        'filesystems.disks.cadangan-uji' => ['driver' => 'local', 'root' => storage_path('framework/testing/cadangan')],
        'database.connections.pgsql.password' => 'rahasia-db',
    ]);
    Storage::fake('cadangan-uji');
});

/** pg_dump stand-in: writes the file named in --file, like the real one. */
function pgDumpTiruan(bool $berhasil = true): void
{
    Process::fake(function (PendingProcess $proses) use ($berhasil) {
        $berkas = collect($proses->command)->first(fn ($a) => str_starts_with($a, '--file='));

        if ($berhasil) {
            File::put(substr((string) $berkas, 7), 'PGDMP isi cadangan');

            return Process::result();
        }

        return Process::result(errorOutput: 'pg_dump: error: connection refused', exitCode: 1);
    });
}

test('a backup is a pg_dump of the database, stored on the backup disk, with the password kept off the command line', function () {
    pgDumpTiruan();

    $this->artisan('cadangan:buat')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'pg_dump'
        && in_array('--format=custom', $p->command, true)
        && ($p->environment['PGPASSWORD'] ?? null) === 'rahasia-db'
        && ! str_contains(implode(' ', $p->command), 'rahasia-db'));

    $berkas = Storage::disk('cadangan-uji')->allFiles('cadangan/basis-data');
    expect($berkas)->toHaveCount(1)
        ->and($berkas[0])->toBe('cadangan/basis-data/2026/2026-09-28_134200.dump')
        ->and(Storage::disk('cadangan-uji')->get($berkas[0]))->toBe('PGDMP isi cadangan')
        // the temporary file does not stay behind
        ->and(glob(storage_path('app/cadangan-*.dump')))->toBe([]);
});

test('a failed dump tells the supervisor and stores nothing', function () {
    pgDumpTiruan(berhasil: false);

    $this->artisan('cadangan:buat')->assertFailed();

    expect(Storage::disk('cadangan-uji')->allFiles())->toBe([])
        ->and(Notifikasi::query()->sole()->only(['jenis', 'judul']))->toBe([
            'jenis' => JenisNotifikasi::WorkflowError->value,
            'judul' => 'Cadangan basis data gagal',
        ]);
});

test('old backups are thinned: 30 days of all, 12 months of monthly, 10 years of yearly', function () {
    // "now" is Monday 28 Sep 2026 13:42
    $nama = fn (string $t) => "cadangan/basis-data/x/{$t}.dump";

    $hapus = BuatCadangan::kedaluwarsa(collect([
        $nama('2026-09-27_020000'),  // yesterday: kept (daily)
        $nama('2026-08-29_020000'),  // 30 days ago: kept (daily)
        $nama('2026-08-15_020000'),  // 44 days ago, not the first of August: deleted
        $nama('2026-08-01_020000'),  // first of August: kept (monthly)
        $nama('2025-10-01_020000'),  // first of Oct 2025, within 12 months: kept (monthly)
        $nama('2025-09-01_020000'),  // first of Sep 2025, past 12 months, not first of 2025: deleted
        $nama('2025-01-01_020000'),  // first of 2025: kept (yearly)
        $nama('2017-01-01_020000'),  // first of 2017, within 10 years: kept (yearly)
        $nama('2016-01-01_020000'),  // first of 2016, past 10 years: deleted
        'cadangan/basis-data/catatan.txt', // not a backup: never touched
    ]));

    expect($hapus->all())->toBe([
        $nama('2016-01-01_020000'),
        $nama('2025-09-01_020000'),
        $nama('2026-08-15_020000'),
    ]);
});

test('the demo makes no backup', function () {
    config(['app.demo' => true]);
    Process::fake();

    $this->artisan('cadangan:buat')->assertSuccessful();

    Process::assertNothingRan();
});
