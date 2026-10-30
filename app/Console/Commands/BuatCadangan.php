<?php

namespace App\Console\Commands;

use App\Enums\JenisNotifikasi;
use App\Support\Demo;
use App\Support\KabarBot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * A full dump of the panel's database (pg_dump, custom format), stored on the backup disk,
 * then the old ones thinned out: every backup for 30 days, the first of each month for 12
 * months, the first of each year for 10 years (BCA CSA butir 5 dan 11). A failure is a
 * notice to the supervisor. The password reaches pg_dump through PGPASSWORD, never the
 * command line, so it does not show in the process list.
 */
#[Signature('cadangan:buat {--tanpa-rapikan : Simpan cadangan baru tanpa menghapus yang lama}')]
#[Description('Cadangkan basis data panel dan rapikan cadangan lama sesuai masa simpan')]
class BuatCadangan extends Command
{
    public function handle(): int
    {
        if (Demo::aktif()) {
            $this->warn('Mode demo: cadangan tidak dibuat.');

            return self::SUCCESS;
        }

        $db = (array) config('database.connections.'.config('database.default'));
        $sementara = storage_path('app/cadangan-'.now()->format('YmdHis').'.dump');
        $tujuan = trim((string) config('cadangan.folder'), '/').'/'.now()->format('Y').'/'.now()->format('Y-m-d_His').'.dump';

        $gagal = function (string $galat) use ($sementara): int {
            File::delete($sementara);
            Log::error('Cadangan basis data gagal', ['galat' => $galat]);
            KabarBot::notifikasi(JenisNotifikasi::WorkflowError, 'Cadangan basis data gagal', mb_substr($galat, 0, 300));
            $this->error('Cadangan gagal: '.$galat);

            return self::FAILURE;
        };

        $hasil = Process::timeout(1800)
            ->env(array_filter([
                'PGPASSWORD' => (string) ($db['password'] ?? ''),
                'PGSSLMODE' => (string) ($db['sslmode'] ?? 'prefer'),
            ], fn ($v) => $v !== ''))
            ->run([
                'pg_dump', '--format=custom', '--no-owner', '--no-privileges',
                '--host='.($db['host'] ?? '127.0.0.1'),
                '--port='.($db['port'] ?? 5432),
                '--username='.($db['username'] ?? 'postgres'),
                '--dbname='.($db['database'] ?? ''),
                '--file='.$sementara,
            ]);

        if ($hasil->failed() || ! File::exists($sementara)) {
            return $gagal(trim($hasil->errorOutput()) ?: 'pg_dump tidak menghasilkan berkas');
        }

        $disk = Storage::disk((string) config('cadangan.disk'));
        $aliran = fopen($sementara, 'rb');

        if ($aliran === false || ! $disk->writeStream($tujuan, $aliran)) {
            is_resource($aliran) && fclose($aliran);

            return $gagal('berkas cadangan tidak dapat disimpan ke disk '.config('cadangan.disk'));
        }

        fclose($aliran);
        $ukuran = File::size($sementara);
        File::delete($sementara);

        Log::info('Cadangan basis data dibuat', ['berkas' => $tujuan, 'byte' => $ukuran]);
        $this->info("Cadangan disimpan: {$tujuan} (".number_format($ukuran / 1024 / 1024, 1).' MB)');

        if (! $this->option('tanpa-rapikan')) {
            $hapus = self::kedaluwarsa(collect($disk->allFiles((string) config('cadangan.folder'))));
            $disk->delete($hapus->all());
            $this->info('Cadangan lama dihapus: '.$hapus->count());
        }

        return self::SUCCESS;
    }

    /**
     * The backups past every keep rule. A backup is kept when it is younger than the daily
     * days, or the first of its month within the monthly months, or the first of its year
     * within the yearly years. Files whose names are not a backup's are never touched.
     *
     * @param  Collection<int, string>  $berkas
     * @return Collection<int, string>
     */
    public static function kedaluwarsa(Collection $berkas): Collection
    {
        $simpan = (array) config('cadangan.simpan');
        // counted in whole days, so the hour a backup ran does not decide its fate
        $sekarang = CarbonImmutable::now()->startOfDay();

        $cadangan = $berkas
            ->mapWithKeys(function (string $jalur) {
                if (! preg_match('/(\d{4}-\d{2}-\d{2}_\d{6})\.dump$/', $jalur, $m)) {
                    return [];
                }

                return [$jalur => CarbonImmutable::createFromFormat('Y-m-d_His', $m[1])];
            })
            ->filter()
            ->sort();

        $pertamaBulan = $cadangan->groupBy(fn ($t) => $t->format('Y-m'), preserveKeys: true)->map(fn ($c) => $c->keys()->first());
        $pertamaTahun = $cadangan->groupBy(fn ($t) => $t->format('Y'), preserveKeys: true)->map(fn ($c) => $c->keys()->first());

        return $cadangan
            ->reject(fn (CarbonImmutable $waktu, string $jalur) => $waktu->gte($sekarang->subDays((int) $simpan['harian']))
                || ($pertamaBulan->contains($jalur) && $waktu->gte($sekarang->subMonths((int) $simpan['bulanan'])))
                || ($pertamaTahun->contains($jalur) && $waktu->gte($sekarang->subYears((int) $simpan['tahunan']))))
            ->keys()
            ->values();
    }
}
