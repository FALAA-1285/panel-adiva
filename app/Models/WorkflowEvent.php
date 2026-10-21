<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\WorkflowEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A workflow run or something it caught (EKSEKUSI, INSIDEN, KONEKSI, LAPORAN).
 *
 * @property int $id
 * @property int|null $workflow_id
 * @property Carbon $waktu
 * @property string $jenis
 * @property string $status
 * @property string $judul
 * @property string|null $detail
 * @property string|null $aksi
 * @property int|null $durasi_ms
 * @property array<string, mixed>|null $data
 */
#[Table('workflow_event')]
#[Fillable([
    'workflow_id', 'waktu', 'jenis', 'status', 'judul', 'detail', 'aksi', 'crm_customer_id',
    'log_order_id', 'n8n_execution_id', 'durasi_ms', 'data',
])]
class WorkflowEvent extends Model
{
    /** @use HasFactory<WorkflowEventFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<CrmCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'crm_customer_id');
    }

    /**
     * @return BelongsTo<LogOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(LogOrder::class, 'log_order_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'waktu' => 'datetime',
            'durasi_ms' => 'integer',
            'data' => 'array',
        ];
    }
}
