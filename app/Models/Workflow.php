<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An n8n workflow behind Adiva.
 *
 * @property int $id
 * @property string $kode
 * @property string $nama
 * @property string $status
 * @property array<string, mixed>|null $pengaturan settings kept on the workflow's nodes (e.g. the chatbot's model)
 */
#[Table('workflow')]
#[Fillable([
    'kode', 'nama', 'n8n_workflow_id', 'jumlah_node', 'jadwal', 'jadwal_keterangan',
    'deskripsi', 'status', 'pengaturan',
])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * @return HasMany<WorkflowEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(WorkflowEvent::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pengaturan' => 'array',
        ];
    }
}
