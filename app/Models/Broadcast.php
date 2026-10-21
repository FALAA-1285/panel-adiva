<?php

namespace App\Models;

use App\Enums\StatusBroadcast;
use App\Jobs\KirimBroadcast;
use App\Models\Concerns\ZonaWaktuAplikasi;
use App\Support\PenerimaBroadcast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $nama
 * @property string|null $segmen_keterangan
 * @property array<string, mixed>|null $filter
 * @property array<string, mixed>|null $opsi
 * @property string $pesan
 * @property int|null $gambar_produk_id
 * @property string $mode
 * @property string $kecepatan
 * @property StatusBroadcast $status
 * @property int $jumlah_target
 * @property Carbon|null $jadwal_at
 * @property Carbon|null $mulai_at
 * @property Carbon|null $selesai_at
 * @property string|null $catatan
 * @property string|null $giliran
 * @property Carbon|null $berikutnya_at
 * @property Carbon|null $created_at
 * @property-read int $jumlah_terkirim
 * @property-read int $jumlah_gagal
 * @property-read int $jumlah_menunggu
 * @property-read int $jumlah_dibaca
 * @property-read int $jumlah_dibalas
 * @property-read int $jumlah_order
 * @property-read int $jumlah_naik
 * @property-read int $jumlah_hot
 * @property-read int $jumlah_stop
 */
#[Table('broadcast')]
#[Fillable([
    'nama', 'segmen_keterangan', 'filter', 'opsi', 'pesan', 'gambar_produk_id', 'mode', 'kecepatan',
    'status', 'jumlah_target', 'jadwal_at', 'mulai_at', 'selesai_at', 'catatan', 'user_id',
    'giliran', 'berikutnya_at',
])]
class Broadcast extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * @return HasMany<BroadcastPenerima, $this>
     */
    public function penerima(): HasMany
    {
        return $this->hasMany(BroadcastPenerima::class);
    }

    /**
     * @return BelongsTo<GambarProduk, $this>
     */
    public function gambar(): BelongsTo
    {
        return $this->belongsTo(GambarProduk::class, 'gambar_produk_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Start (or restart) sending: a new chain of KirimBroadcast jobs takes over, any
     * older one stops at its next step. A scheduled campaign waits for jadwal_at.
     */
    public function mulaiKirim(): void
    {
        $kapan = $this->jadwal_at?->isFuture() ? $this->jadwal_at : now();
        $this->update(['giliran' => (string) Str::uuid(), 'berikutnya_at' => $kapan]);

        KirimBroadcast::jadwalkan($this->id, (string) $this->giliran, $kapan);
    }

    /**
     * "Daftar penerima dikunci saat kampanye mulai": one MENUNGGU row per customer who
     * matches the filter now, sent from the chosen bot number or their own.
     *
     * @return int the number of recipients
     */
    public function kunciPenerima(): int
    {
        $nomorBot = $this->opsi['nomor_bot'] ?? null;
        $penerima = PenerimaBroadcast::query(PenerimaBroadcast::normalisasi($this->filter ?? []))->get(['id', 'chat_id', 'sesi_wa']);

        $penerima->chunk(500)->each(fn (Collection $potongan) => BroadcastPenerima::query()->insertOrIgnore($potongan->map(fn (CrmCustomer $c) => [
            'broadcast_id' => $this->id,
            'crm_customer_id' => $c->id,
            'chat_id' => $c->chat_id,
            'sesi_wa' => filled($nomorBot) ? $nomorBot : $c->sesi_wa,
            'status' => BroadcastPenerima::MENUNGGU,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all()));

        return $penerima->count();
    }

    /**
     * Each step of the campaign report (Broadcast.dc.html), counted from the recipients.
     *
     * @param  Builder<Broadcast>  $query
     */
    #[Scope]
    protected function denganHitungan(Builder $query): void
    {
        $query->withCount([
            'penerima as jumlah_terkirim' => fn (Builder $q) => $q->whereNotNull('terkirim_at'),
            'penerima as jumlah_gagal' => fn (Builder $q) => $q->where('status', BroadcastPenerima::GAGAL),
            'penerima as jumlah_menunggu' => fn (Builder $q) => $q->where('status', BroadcastPenerima::MENUNGGU),
            'penerima as jumlah_dibaca' => fn (Builder $q) => $q->whereNotNull('dibaca_at'),
            'penerima as jumlah_dibalas' => fn (Builder $q) => $q->whereNotNull('dibalas_at'),
            'penerima as jumlah_order' => fn (Builder $q) => $q->whereNotNull('log_order_id'),
            'penerima as jumlah_naik' => fn (Builder $q) => $q->where('naik_tahap', true),
            'penerima as jumlah_hot' => fn (Builder $q) => $q->where('jadi_hot', true),
            'penerima as jumlah_stop' => fn (Builder $q) => $q->where('minta_stop', true),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'opsi' => 'array',
            'status' => StatusBroadcast::class,
            'jumlah_target' => 'integer',
            'jadwal_at' => 'datetime',
            'mulai_at' => 'datetime',
            'selesai_at' => 'datetime',
            'berikutnya_at' => 'datetime',
        ];
    }
}
