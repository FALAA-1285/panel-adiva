<?php

namespace App\Models;

use App\Enums\SumberAktivitas;
use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $waktu
 * @property int|null $user_id
 * @property string $pelaku
 * @property SumberAktivitas $sumber
 * @property string $kategori
 * @property string $aksi
 * @property string|null $detail
 * @property array<string, mixed>|null $data
 */
#[Table('activity_log')]
#[Fillable([
    'waktu', 'user_id', 'pelaku', 'sumber', 'kategori', 'aksi', 'detail', 'subjek_type', 'subjek_id', 'data',
])]
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subjek(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'waktu' => 'datetime',
            'sumber' => SumberAktivitas::class,
            'data' => 'array',
        ];
    }
}
