<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One store setting (CONFIG_TOKO), e.g. BATAS_BAYAR_JAM = 5.
 */
#[Table('config_toko')]
#[Fillable(['kunci', 'nilai', 'keterangan'])]
class ConfigToko extends Model
{
    use ZonaWaktuAplikasi;
}
