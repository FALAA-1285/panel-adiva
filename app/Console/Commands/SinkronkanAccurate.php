<?php

namespace App\Console\Commands;

use App\Accurate\AccurateGagal;
use App\Accurate\BacaAccurate;
use App\Accurate\SinkronBarangToko;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Toko Sawojajar's goods from Accurate into barang_toko (every minute). With --coba it only shows
 * what Accurate sent, to check the connection and the field names, and saves nothing.
 */
#[Signature('accurate:sinkron {--coba : tampilkan hasil dari Accurate tanpa menyimpan}')]
#[Description('Sinkronkan barang Toko Sawojajar dari Accurate (hanya baca)')]
class SinkronkanAccurate extends Command
{
    public function handle(BacaAccurate $accurate, SinkronBarangToko $sinkron): int
    {
        if ($this->option('coba')) {
            return $this->coba($accurate);
        }

        $hasil = $sinkron->jalankan();

        if ($hasil->status !== 'BERHASIL') {
            $this->error('Gagal: '.$hasil->galat);

            return self::FAILURE;
        }

        $this->info("{$hasil->jumlah} barang toko tersimpan");

        return self::SUCCESS;
    }

    private function coba(BacaAccurate $accurate): int
    {
        try {
            $semua = $accurate->barang();
        } catch (AccurateGagal $e) {
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $toko = SinkronBarangToko::dijualDiToko($semua);

        $this->info(count($semua)." barang di Accurate · {$toko->count()} dijual di toko (ber-barcode, aktif) · tidak ada yang disimpan");
        $this->table(
            ['ID', 'Kode', 'Nama', 'Barcode', 'Kategori', 'Satuan', 'Harga', 'Nonaktif'],
            collect($semua)->take(15)->map(fn (array $b) => [
                $b['accurate_id'], $b['kode'], $b['nama'], $b['barcode'], $b['kategori'], $b['satuan'], $b['harga'], $b['nonaktif'] ? 'ya' : '',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
