<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row archived by the Arsip Data workflow, copied whole into data.
 */
#[Table('arsip_data')]
#[Fillable(['jenis', 'kunci', 'tanggal', 'data', 'diarsipkan'])]
class ArsipData extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal' => 'datetime',
            'data' => 'array',
            'diarsipkan' => 'datetime',
        ];
    }
}
