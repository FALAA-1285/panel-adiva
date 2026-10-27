<?php

namespace App\Support;

use App\Models\GambarProduk;
use App\Models\Karyawan;
use App\Models\Kebijakan;
use App\Models\PaketReseller;
use App\Models\Produk;

class TabKatalog
{
    /**
     * Counts on the five "Katalog & pengetahuan" tabs.
     *
     * @return array{produk: int, paket: int, gambar: int, kebijakan: int, karyawan: int}
     */
    public static function hitungan(): array
    {
        return [
            'produk' => Produk::query()->dijual()->count(),
            'paket' => PaketReseller::query()->count(),
            'gambar' => GambarProduk::query()->count(),
            'kebijakan' => Kebijakan::query()->count(),
            'karyawan' => Karyawan::query()->count(),
        ];
    }
}
