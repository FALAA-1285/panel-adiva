<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\TemplateFollowupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A follow-up message sent by Prospek Ulang Otomatis (TEMPLATE_FOLLOWUP).
 *
 * @property int $id
 * @property string $kode FU-01 … FU-05; later steps are FU-01-2, FU-01-3, …
 * @property string|null $judul
 * @property string|null $kondisi_pemicu
 * @property string|null $tahap comma separated funnel stages, or SELESAI
 * @property string|null $acuan_waktu CHAT_TERAKHIR, ORDER_DITUTUP or SELESAI
 * @property string $pesan
 * @property bool $status_aktif
 */
#[Table('template_followup')]
#[Fillable(['kode', 'judul', 'kondisi_pemicu', 'tahap', 'acuan_waktu', 'pesan', 'jeda_hari', 'status_aktif'])]
class TemplateFollowup extends Model
{
    /** @use HasFactory<TemplateFollowupFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * @return list<string>
     */
    public function daftarTahap(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->tahap))));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_aktif' => 'boolean',
        ];
    }
}
