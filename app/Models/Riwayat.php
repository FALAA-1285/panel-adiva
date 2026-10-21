<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A timeline line on an order or a customer.
 *
 * @property Carbon $waktu
 * @property string $jenis
 * @property string $teks
 * @property string $nada
 * @property array<string, mixed>|null $data
 */
#[Table('riwayat')]
#[Fillable(['subjek_type', 'subjek_id', 'waktu', 'jenis', 'teks', 'nada', 'data'])]
class Riwayat extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * @return MorphTo<Model, $this>
     */
    public function subjek(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * "13:06" within the last day, "25 Sep" before that.
     */
    public function waktuSingkat(): string
    {
        return $this->waktu->greaterThanOrEqualTo(now()->subDay()) ? $this->waktu->format('H:i') : $this->waktu->translatedFormat('j M');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'waktu' => 'datetime',
            'data' => 'array',
        ];
    }
}
