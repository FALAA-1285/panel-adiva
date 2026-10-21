<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A preset for a new campaign (BroadcastNew.dc.html "Template: Restock reseller").
 *
 * @property int $id
 * @property string $nama
 * @property string|null $nama_kampanye
 * @property array<string, mixed>|null $filter
 * @property string|null $pesan
 * @property int|null $gambar_produk_id
 * @property list<string>|null $tombol
 * @property int $urutan
 */
#[Table('template_broadcast')]
#[Fillable(['nama', 'nama_kampanye', 'filter', 'pesan', 'gambar_produk_id', 'tombol', 'urutan'])]
class TemplateBroadcast extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'tombol' => 'array',
            'urutan' => 'integer',
        ];
    }
}
