<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\ProdukFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $diarsip_at no longer sold; kept for old order lines and photos
 * @property-read bool|null $ada_foto
 */
#[Table('produk')]
#[Fillable([
    'jenis_produk', 'kategori_produk', 'brand', 'kategori_kemasan', 'gramasi',
    'deskripsi', 'rasa', 'informasi_harga', 'target_pasar', 'harga_bertingkat', 'diarsip_at',
])]
class Produk extends Model
{
    /** @use HasFactory<ProdukFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * Products still sold: what Adiva quotes, broadcasts target and prices are checked against.
     *
     * @param  Builder<Produk>  $query
     */
    #[Scope]
    protected function dijual(Builder $query): void
    {
        $query->whereNull('diarsip_at');
    }

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
            'diarsip_at' => 'datetime',
        ];
    }
}
