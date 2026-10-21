<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('harga_karyawan')]
#[Fillable([
    'varian_produk', 'singkatan', 'jenis_produk', 'brand', 'gramasi', 'kategori_kemasan',
    'rasa', 'satuan', 'keterangan', 'batas', 'harga_karyawan',
])]
class HargaKaryawan extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'harga_karyawan' => 'integer',
        ];
    }
}
