<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\PaketResellerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $kode_paket
 * @property string $nama_paket
 * @property string|null $isi
 * @property list<array{nama: string, jumlah: string}>|null $isi_detail
 * @property string|null $cocok_untuk
 * @property string|null $catatan
 * @property string|null $tawarkan_ketika
 * @property string|null $berat
 * @property int|null $harga
 * @property string|null $status_data
 * @property int|null $diubah_oleh_id
 * @property Carbon|null $updated_at
 * @property-read int|string|null $terjual
 */
#[Table('paket_reseller')]
#[Fillable([
    'kode_paket', 'nama_paket', 'isi', 'isi_detail', 'cocok_untuk', 'catatan',
    'tawarkan_ketika', 'berat', 'harga', 'status_data', 'diubah_oleh_id',
])]
class PaketReseller extends Model
{
    /** @use HasFactory<PaketResellerFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Who saved it last ("terakhir diubah … oleh Falah").
     *
     * @return BelongsTo<User, $this>
     */
    public function diubahOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diubah_oleh_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'isi_detail' => 'array',
            'harga' => 'integer',
        ];
    }
}
