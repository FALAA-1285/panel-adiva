<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The invoice PDF n8n made at ACC and sent to the customer ("Unduh PDF").
 *
 * @property int $id
 * @property int $log_order_id
 * @property string $nama_file
 * @property string|resource $isi the PDF bytes (bytea)
 */
#[Table('invoice_pdf')]
#[Fillable(['log_order_id', 'nama_file', 'isi'])]
class InvoicePdf extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * @return BelongsTo<LogOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(LogOrder::class, 'log_order_id');
    }

    /**
     * PostgreSQL hands bytea back as a stream.
     */
    public function bytes(): string
    {
        return is_resource($this->isi) ? (string) stream_get_contents($this->isi) : (string) $this->isi;
    }
}
