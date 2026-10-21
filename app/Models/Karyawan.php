<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Verified employee (KARYAWAN): only these numbers get employee prices.
 *
 * @property int $id
 * @property string $nama
 * @property string|null $nomor_wa
 * @property string|null $bagian
 * @property string $status
 * @property-read int|null $order_count
 * @property-read int|string|null $potong_gaji
 */
#[Table('karyawan')]
#[Fillable(['nama', 'nomor_wa', 'bagian', 'status', 'catatan'])]
class Karyawan extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * Orders placed from this employee's number (n8n recognises employees by number).
     *
     * @return HasMany<LogOrder, $this>
     */
    public function order(): HasMany
    {
        return $this->hasMany(LogOrder::class, 'nomor_wa', 'nomor_wa');
    }
}
