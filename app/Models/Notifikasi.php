<?php

namespace App\Models;

use App\Enums\JenisNotifikasi;
use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\NotifikasiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * A notice to the supervisor or Bu Sarah, written by n8n; the panel sends its short
 * WhatsApp (App\Support\KabarWa).
 * Read state is shared by the whole team.
 *
 * @property int $id
 * @property Carbon $waktu
 * @property string $jenis
 * @property string $kategori
 * @property string $penerima
 * @property string $judul
 * @property string|null $detail
 * @property int|null $crm_customer_id
 * @property int|null $log_order_id
 * @property Carbon|null $dibaca_at
 * @property Carbon|null $selesai_at
 * @property string|null $wa_status TERKIRIM, GAGAL, MATI (switched off in Pengaturan) or PANEL (not a WhatsApp kind)
 * @property Carbon|null $wa_dikirim_at
 */
#[Table('notifikasi')]
#[Fillable([
    'waktu', 'jenis', 'kategori', 'penerima', 'judul', 'detail', 'crm_customer_id', 'log_order_id',
    'broadcast_id', 'dibaca_at', 'selesai_at',
])]
class Notifikasi extends Model
{
    /** @use HasFactory<NotifikasiFactory> */
    use HasFactory, ZonaWaktuAplikasi;

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
     * @return BelongsTo<Broadcast, $this>
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /**
     * The type, when the panel knows it (n8n may send newer ones).
     */
    public function jenisDikenal(): ?JenisNotifikasi
    {
        return JenisNotifikasi::tryFrom($this->jenis);
    }

    public function tandaiDibaca(): void
    {
        if ($this->dibaca_at === null) {
            $this->update(['dibaca_at' => now()]);
        }
    }

    /**
     * The matter behind these notifications is over: an order left "Menunggu ACC", a
     * customer no longer needs an admin. They leave "Perlu tindakan" and stop counting
     * as unread — nobody needs to act on them any more, so the bell should not say so.
     * They stay in the list, read, as a record of what happened.
     *
     * @param  list<JenisNotifikasi>  $jenis
     */
    public static function selesaikan(array $jenis, ?int $orderId = null, ?int $customerId = null): void
    {
        if ($orderId === null && $customerId === null) {
            return;
        }

        static::query()
            ->whereIn('jenis', array_map(fn (JenisNotifikasi $j) => $j->value, $jenis))
            ->whereNull('selesai_at')
            ->when($orderId !== null, fn (Builder $q) => $q->where('log_order_id', $orderId))
            ->when($orderId === null, fn (Builder $q) => $q->where('crm_customer_id', $customerId))
            ->update([
                'selesai_at' => now(),
                'dibaca_at' => DB::raw('coalesce(dibaca_at, now())'),
            ]);
    }

    /**
     * Per kategori: how many are listed (semua) and how many of those are unread
     * (belum), in one query instead of one count per tab.
     *
     * @return Collection<int, stdClass>
     */
    public static function rekapPerKategori(int $hari): Collection
    {
        return static::query()
            ->terlihat($hari)
            ->toBase()
            ->selectRaw('kategori, count(*) as semua, count(*) filter (where dibaca_at is null) as belum')
            ->groupBy('kategori')
            ->get();
    }

    /**
     * @param  Builder<Notifikasi>  $query
     */
    #[Scope]
    protected function belumDibaca(Builder $query): void
    {
        $query->whereNull('dibaca_at');
    }

    /**
     * The last $hari days (today included), plus anything still unread, so everything
     * counted on the bell can be found.
     *
     * @param  Builder<Notifikasi>  $query
     */
    #[Scope]
    protected function terlihat(Builder $query, int $hari): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('waktu', '>=', now()->startOfDay()->subDays($hari - 1))
            ->orWhereNull('dibaca_at'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'waktu' => 'datetime',
            'dibaca_at' => 'datetime',
            'selesai_at' => 'datetime',
            'wa_dikirim_at' => 'datetime',
        ];
    }
}
