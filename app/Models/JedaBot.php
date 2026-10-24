<?php

namespace App\Models;

use App\Enums\JenisNotifikasi;
use App\Models\Concerns\ZonaWaktuAplikasi;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bot pause for one customer. Rows stay after LANJUT (selesai_at is set), so the
 * same table is the pause history; sampai = null means "tanpa batas".
 *
 * @property int $id
 * @property int|null $crm_customer_id
 * @property string|null $nomor
 * @property string $chat_id
 * @property string|null $nama
 * @property string|null $sesi_wa
 * @property Carbon $sejak
 * @property Carbon|null $sampai
 * @property string|null $oleh
 * @property string|null $alasan
 * @property bool $sudah_diberi_tahu
 * @property Carbon|null $selesai_at
 * @property string|null $catatan_selesai
 * @property string|null $diakhiri_oleh
 */
#[Table('jeda_bot')]
#[Fillable([
    'crm_customer_id', 'nomor', 'chat_id', 'nama', 'sesi_wa', 'sejak', 'sampai', 'oleh', 'alasan',
    'sudah_diberi_tahu', 'selesai_at', 'catatan_selesai', 'diakhiri_oleh',
])]
class JedaBot extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * @return BelongsTo<CrmCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'crm_customer_id');
    }

    /**
     * Pauses that have not been ended with LANJUT.
     *
     * @param  Builder<JedaBot>  $query
     */
    #[Scope]
    protected function aktif(Builder $query): void
    {
        $query->whereNull('selesai_at');
    }

    /**
     * The pauses of one customer, found by phone number (62…) or chat id.
     *
     * @param  Builder<JedaBot>  $query
     */
    #[Scope]
    protected function untuk(Builder $query, string $nomor, string $chatId): void
    {
        $query->where(fn (Builder $q) => $q->where('nomor', $nomor)->orWhereRaw('lower(chat_id) = ?', [strtolower($chatId)]));
    }

    /**
     * Pauses that still hold the bot back: not ended and not past their end time.
     *
     * @param  Builder<JedaBot>  $query
     */
    #[Scope]
    protected function menahan(Builder $query): void
    {
        $query->whereNull('selesai_at')->where(fn (Builder $q) => $q->whereNull('sampai')->orWhere('sampai', '>', now()));
    }

    /**
     * Whether the end time has not come yet. Compared to the minute, as the pause is
     * written: "until 14:30" still holds at 14:30.
     */
    public function masihBerlaku(): bool
    {
        return $this->sampai === null || $this->sampai->format('Y-m-d H:i') >= now()->format('Y-m-d H:i');
    }

    /**
     * The pause ran out: ends every running pause of this customer, by "Sistem", at the end time.
     */
    public static function akhiriKedaluwarsa(string $nomor, string $chatId): void
    {
        foreach (static::query()->aktif()->untuk($nomor, $chatId)->get() as $jeda) {
            $jeda->update([
                'selesai_at' => $jeda->sampai !== null && $jeda->sampai->isBefore(now()) ? $jeda->sampai : now(),
                'diakhiri_oleh' => 'Sistem',
            ]);
        }
    }

    /**
     * Pauses that have been ended.
     *
     * @param  Builder<JedaBot>  $query
     */
    #[Scope]
    protected function selesai(Builder $query): void
    {
        $query->whereNotNull('selesai_at');
    }

    /**
     * Pauses that are over: ended with LANJUT, or past their end time even though nobody
     * has closed them yet. A pause that runs out is only closed when the customer writes
     * again (AlurPesanMasuk), so without this it would sit in neither list: no longer
     * holding the bot back, yet not in the history either.
     *
     * @param  Builder<JedaBot>  $query
     */
    #[Scope]
    protected function berakhir(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNotNull('selesai_at')
            ->orWhere(fn (Builder $q) => $q->whereNotNull('sampai')->where('sampai', '<=', now())));
    }

    /**
     * When the pause ended: by LANJUT, or else when its time ran out. Null while it holds.
     */
    public function akhir(): ?CarbonInterface
    {
        if ($this->selesai_at !== null) {
            return $this->selesai_at;
        }

        return $this->sampai !== null && $this->sampai->lessThanOrEqualTo(now()) ? $this->sampai : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sejak' => 'datetime',
            'sampai' => 'datetime',
            'sudah_diberi_tahu' => 'boolean',
            'selesai_at' => 'datetime',
        ];
    }

    /**
     * A pause that ends (LANJUT, or closed as run out) no longer needs an admin to reply.
     */
    protected static function booted(): void
    {
        static::updated(function (JedaBot $jeda) {
            if ($jeda->wasChanged('selesai_at') && $jeda->selesai_at !== null && $jeda->crm_customer_id !== null) {
                Notifikasi::selesaikan(
                    [JenisNotifikasi::JedaChat, JenisNotifikasi::JedaKedaluwarsa, JenisNotifikasi::PengingatJeda],
                    customerId: $jeda->crm_customer_id,
                );
            }
        });
    }
}
