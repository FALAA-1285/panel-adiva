<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\KebijakanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $kode
 * @property string $topik
 * @property string|null $pertanyaan_customer_umum
 * @property string|null $jawaban_resmi
 * @property string|null $kata_pemicu
 * @property string|null $status_data
 * @property Carbon|null $updated_at
 * @property-read int|null $dipakai_count
 */
#[Table('kebijakan')]
#[Fillable(['kode', 'topik', 'pertanyaan_customer_umum', 'jawaban_resmi', 'kata_pemicu', 'status_data'])]
class Kebijakan extends Model
{
    /** @use HasFactory<KebijakanFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * Times Adiva answered with this policy.
     *
     * @return HasMany<PemakaianKebijakan, $this>
     */
    public function pemakaian(): HasMany
    {
        return $this->hasMany(PemakaianKebijakan::class);
    }
}
