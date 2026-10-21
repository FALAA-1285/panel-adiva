<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $broadcast_id
 * @property int|null $crm_customer_id
 * @property string $chat_id
 * @property string $status MENUNGGU, TERKIRIM or GAGAL
 * @property Carbon|null $terkirim_at
 * @property Carbon|null $dibaca_at
 * @property Carbon|null $dibalas_at
 * @property string|null $balasan
 * @property int|null $log_order_id
 * @property bool $minta_stop
 * @property string|null $keterangan_gagal
 * @property string|null $sesi_wa
 * @property string|null $wa_message_id
 */
#[Table('broadcast_penerima')]
#[Fillable([
    'broadcast_id', 'crm_customer_id', 'chat_id', 'sesi_wa', 'status', 'terkirim_at', 'dibaca_at',
    'dibalas_at', 'balasan', 'log_order_id', 'naik_tahap', 'jadi_hot', 'minta_stop', 'keterangan_gagal',
    'wa_message_id',
])]
class BroadcastPenerima extends Model
{
    use ZonaWaktuAplikasi;

    public const MENUNGGU = 'MENUNGGU';

    public const TERKIRIM = 'TERKIRIM';

    public const GAGAL = 'GAGAL';

    /**
     * @return BelongsTo<Broadcast, $this>
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /**
     * @return BelongsTo<CrmCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'crm_customer_id');
    }

    /**
     * Order placed after this broadcast, if any.
     *
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
            'terkirim_at' => 'datetime',
            'dibaca_at' => 'datetime',
            'dibalas_at' => 'datetime',
            'naik_tahap' => 'boolean',
            'jadi_hot' => 'boolean',
            'minta_stop' => 'boolean',
        ];
    }
}
