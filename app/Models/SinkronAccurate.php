<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of accurate:sinkron (hourly, or "Sinkron sekarang" on Katalog → Barang toko).
 *
 * @property int $id
 * @property Carbon $mulai
 * @property Carbon|null $selesai
 * @property string $status BERJALAN, BERHASIL or GAGAL
 * @property int|null $jumlah goods kept after the run
 * @property string|null $galat
 * @property int|null $oleh_id who pressed "Sinkron sekarang"; null for the hourly run
 */
#[Table('sinkron_accurate')]
#[Fillable(['mulai', 'selesai', 'status', 'jumlah', 'galat', 'oleh_id'])]
class SinkronAccurate extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * The last run that went through.
     */
    public static function terakhirBerhasil(): ?self
    {
        return self::query()->where('status', 'BERHASIL')->latest('mulai')->latest('id')->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function oleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oleh_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mulai' => 'datetime',
            'selesai' => 'datetime',
            'jumlah' => 'integer',
        ];
    }
}
