<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A good on Toko Sawojajar's shelf, as Accurate has it (read only, see SinkronBarangToko).
 * Sold at the shop only: it cannot be ordered over WhatsApp.
 *
 * @property int $id
 * @property int $accurate_id
 * @property string|null $kode
 * @property string $nama
 * @property string $barcode
 * @property string|null $kategori
 * @property string|null $satuan
 * @property int|null $harga Accurate's main selling price
 * @property Carbon $disinkron_at
 */
#[Table('barang_toko')]
#[Fillable(['accurate_id', 'kode', 'nama', 'barcode', 'kategori', 'satuan', 'harga', 'disinkron_at'])]
class BarangToko extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accurate_id' => 'integer',
            'harga' => 'integer',
            'disinkron_at' => 'datetime',
        ];
    }
}
