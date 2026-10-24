<?php

namespace App\Models;

use App\Enums\JenisNotifikasi;
use App\Enums\SkorProspek;
use App\Enums\StatusOrder;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Models\Concerns\ZonaWaktuAplikasi;
use App\Support\NomorWa;
use Database\Factories\CrmCustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $chat_id
 * @property string|null $nomor_wa
 * @property string|null $nama
 * @property string|null $sesi_wa
 * @property TipeCustomer $tipe_customer
 * @property TahapFunnel $tahap_funnel
 * @property array<string, string>|null $tahap_sejak stage => when it was reached
 * @property int|null $tahap_manual_oleh_id
 * @property Carbon|null $tahap_manual_at
 * @property string|null $minat_produk
 * @property string|null $lokasi_jualan
 * @property int|null $estimasi_modal
 * @property string|null $ringkasan_terakhir
 * @property string|null $pesan_terakhir_customer
 * @property int $jumlah_followup
 * @property bool $status_optout
 * @property string|null $catatan
 * @property string|null $catatan_tetap
 * @property int $mem_gen
 * @property Carbon|null $created_at
 * @property SkorProspek $skor_prospek
 * @property string|null $kota
 * @property bool $butuh_admin
 * @property Carbon|null $tgl_interaksi_terakhir
 * @property Carbon|null $tgl_followup_berikutnya
 */
#[Table('crm_customer')]
#[Fillable([
    'chat_id', 'nomor_wa', 'nama', 'sesi_wa', 'tipe_customer', 'tahap_funnel', 'tahap_sejak', 'tahap_manual_oleh_id', 'tahap_manual_at', 'skor_prospek',
    'minat_produk', 'lokasi_jualan', 'estimasi_modal', 'kota', 'ringkasan_terakhir',
    'pesan_terakhir_customer', 'jumlah_followup', 'tgl_interaksi_terakhir', 'tgl_followup_berikutnya',
    'status_optout', 'butuh_admin', 'alasan_butuh_admin', 'catatan', 'catatan_tetap', 'mem_gen',
])]
class CrmCustomer extends Model
{
    /** @use HasFactory<CrmCustomerFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * The name, or the masked number for someone who has not said it yet
     * (Pipeline.dc.html: "+62 878-••••-5120").
     */
    public function namaTampil(): string
    {
        return $this->nama ?? NomorWa::samarkanKartu($this->nomor_wa ?? $this->chat_id);
    }

    /**
     * Who last moved the stage by hand.
     *
     * @return BelongsTo<User, $this>
     */
    public function tahapManualOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tahap_manual_oleh_id');
    }

    /**
     * @return HasMany<LogOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(LogOrder::class);
    }

    /**
     * An order still running: waiting for ACC, waiting for payment, or paid
     * (OrderNew.dc.html: a new order needs "Pesanan tambahan terpisah").
     */
    public function orderBerjalan(): ?LogOrder
    {
        return $this->orders()
            ->whereIn('status_order', [StatusOrder::MenungguKonfirmasi, StatusOrder::Diacc, StatusOrder::Lunas])
            ->latest('tanggal')
            ->first();
    }

    /**
     * @return HasMany<Pesan, $this>
     */
    public function pesan(): HasMany
    {
        return $this->hasMany(Pesan::class);
    }

    /**
     * @return HasMany<JedaBot, $this>
     */
    public function jeda(): HasMany
    {
        return $this->hasMany(JedaBot::class);
    }

    /**
     * @return MorphMany<Riwayat, $this>
     */
    public function riwayat(): MorphMany
    {
        return $this->morphMany(Riwayat::class, 'subjek');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipe_customer' => TipeCustomer::class,
            'tahap_funnel' => TahapFunnel::class,
            'tahap_sejak' => 'array',
            'tahap_manual_at' => 'datetime',
            'skor_prospek' => SkorProspek::class,
            'estimasi_modal' => 'integer',
            'tgl_interaksi_terakhir' => 'datetime',
            'tgl_followup_berikutnya' => 'datetime',
            'status_optout' => 'boolean',
            'butuh_admin' => 'boolean',
        ];
    }

    /**
     * Once nobody is flagged to step in for this customer, the "butuh admin" notices are done.
     */
    protected static function booted(): void
    {
        static::updated(function (CrmCustomer $customer) {
            if ($customer->wasChanged('butuh_admin') && ! $customer->butuh_admin) {
                Notifikasi::selesaikan(
                    [JenisNotifikasi::ButuhAdmin, JenisNotifikasi::LampiranMasuk],
                    customerId: $customer->id,
                );
            }
        });
    }
}
