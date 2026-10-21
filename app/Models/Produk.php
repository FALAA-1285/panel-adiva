<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\ProdukFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $jenis_produk
 * @property string|null $kategori_produk
 * @property string|null $brand
 * @property string|null $kategori_kemasan
 * @property string|null $gramasi
 * @property string|null $deskripsi
 * @property string|null $rasa
 * @property string|null $informasi_harga
 * @property string|null $target_pasar
 * @property array{satuan: string, tier: list<array{min: int, max: int|null, harga: int|null}>}|null $harga_bertingkat
 * @property-read bool|null $ada_foto
 */
#[Table('produk')]
#[Fillable([
    'jenis_produk', 'kategori_produk', 'brand', 'kategori_kemasan', 'gramasi',
    'deskripsi', 'rasa', 'informasi_harga', 'target_pasar', 'harga_bertingkat',
])]
class Produk extends Model
{
    /** @use HasFactory<ProdukFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * @return HasMany<GambarProduk, $this>
     */
    public function gambar(): HasMany
    {
        return $this->hasMany(GambarProduk::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'harga_bertingkat' => 'array',
        ];
    }
}
