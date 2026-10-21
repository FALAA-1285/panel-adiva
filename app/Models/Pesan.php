<?php

namespace App\Models;

use App\Enums\PengirimPesan;
use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\PesanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One chat line with a customer.
 *
 * @property int $id
 * @property int|null $crm_customer_id
 * @property string $chat_id
 * @property string|null $sesi_wa
 * @property Carbon $waktu
 * @property PengirimPesan $pengirim
 * @property int|null $user_id
 * @property string|null $teks
 * @property int|null $gambar_produk_id
 * @property string|null $lampiran_keterangan
 * @property list<string>|null $tombol
 * @property string|null $jalur
 * @property string|null $status_kirim
 * @property array<string, mixed>|null $meta
 */
#[Table('pesan')]
#[Fillable([
    'crm_customer_id', 'chat_id', 'sesi_wa', 'waktu', 'pengirim', 'user_id', 'teks', 'gambar_produk_id',
    'lampiran_url', 'lampiran_keterangan', 'tombol', 'jalur', 'status_kirim', 'wa_message_id',
    'broadcast_id', 'meta',
])]
class Pesan extends Model
{
    /** @use HasFactory<PesanFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * @return BelongsTo<CrmCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'crm_customer_id');
    }

    /**
     * Admin who wrote the line (pengirim ADMIN).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<GambarProduk, $this>
     */
    public function gambar(): BelongsTo
    {
        return $this->belongsTo(GambarProduk::class, 'gambar_produk_id');
    }

    /**
     * @return BelongsTo<Broadcast, $this>
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'waktu' => 'datetime',
            'pengirim' => PengirimPesan::class,
            'tombol' => 'array',
            'meta' => 'array',
        ];
    }
}
