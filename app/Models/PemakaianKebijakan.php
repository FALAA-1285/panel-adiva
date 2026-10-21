<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\PemakaianKebijakanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adiva answered a customer with this policy (KB_Kebijakan tool).
 */
#[Table('pemakaian_kebijakan')]
#[Fillable(['kebijakan_id', 'crm_customer_id', 'pertanyaan', 'waktu'])]
class PemakaianKebijakan extends Model
{
    /** @use HasFactory<PemakaianKebijakanFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * @return BelongsTo<Kebijakan, $this>
     */
    public function kebijakan(): BelongsTo
    {
        return $this->belongsTo(Kebijakan::class);
    }

    /**
     * @return BelongsTo<CrmCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'crm_customer_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'waktu' => 'datetime',
        ];
    }
}
