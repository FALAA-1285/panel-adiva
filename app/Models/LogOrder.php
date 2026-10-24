<?php

namespace App\Models;

use App\Enums\JenisNotifikasi;
use App\Enums\StatusOrder;
use App\Enums\TipeCustomer;
use App\Models\Concerns\ZonaWaktuAplikasi;
use App\Support\PengaturanToko;
use Database\Factories\LogOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $order_id
 * @property int|null $crm_customer_id
 * @property string|null $nomor_wa
 * @property string|null $nama_penerima
 * @property Carbon $tanggal
 * @property StatusOrder $status_order
 * @property TipeCustomer|null $tipe_customer
 * @property int $total_produk
 * @property int $ongkir
 * @property string|null $invoice_number
 * @property string|null $invoice_pdf_url the PDF n8n made
 * @property string|null $no_hp
 * @property string|null $metode_bayar
 * @property string|null $kurir_pilihan
 * @property string|null $kurir_final
 * @property string|null $estimasi_kirim
 * @property string|null $no_resi
 * @property string|null $berat_kg
 * @property bool $ambil_sendiri
 * @property string|null $alamat_lengkap street and number; the area is in the columns below
 * @property string|null $kelurahan
 * @property string|null $kecamatan
 * @property string|null $kota
 * @property string|null $provinsi
 * @property string|null $kode_pos
 * @property string|null $sesi_wa
 * @property string|null $catatan_admin
 * @property int|null $dicatat_oleh_id
 * @property string|null $dobel_dengan
 * @property Carbon|null $bukti_masuk_at
 * @property Carbon|null $diacc_at
 * @property Carbon|null $lunas_at
 * @property Carbon|null $dikirim_at
 * @property Carbon|null $siap_diambil_at
 * @property Carbon|null $selesai_at
 * @property Carbon|null $batas_bayar_at
 */
#[Table('log_order')]
#[Fillable([
    'order_id', 'invoice_number', 'invoice_pdf_url', 'crm_customer_id', 'chat_id', 'nomor_wa', 'sesi_wa',
    'nama_penerima', 'no_hp', 'tanggal', 'status_order', 'metode_bayar', 'tipe_customer',
    'total_produk', 'ongkir', 'berat_kg', 'kurir_pilihan', 'kurir_final', 'estimasi_kirim', 'no_resi', 'ambil_sendiri',
    'alamat_lengkap', 'kelurahan', 'kecamatan', 'kota', 'provinsi', 'kode_pos', 'jadwal_pickup',
    'catatan_admin', 'dicatat_oleh_id', 'pesanan_tambahan', 'dobel_dengan', 'bukti_masuk_at',
    'diacc_at', 'lunas_at', 'dikirim_at', 'siap_diambil_at', 'selesai_at', 'batas_bayar_at', 'diingatkan_at',
])]
class LogOrder extends Model
{
    /** @use HasFactory<LogOrderFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * @return HasOne<InvoicePdf, $this>
     */
    public function invoicePdf(): HasOne
    {
        return $this->hasOne(InvoicePdf::class, 'log_order_id');
    }

    /**
     * @return BelongsTo<CrmCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'crm_customer_id');
    }

    /**
     * The verified employee behind a KARYAWAN order, matched by number.
     *
     * @return BelongsTo<Karyawan, $this>
     */
    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'nomor_wa', 'nomor_wa');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('urutan');
    }

    /**
     * Admin who recorded the order manually (CATAT); null when Adiva recorded it.
     *
     * @return BelongsTo<User, $this>
     */
    public function dicatatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh_id');
    }

    /**
     * @return MorphMany<Riwayat, $this>
     */
    public function riwayat(): MorphMany
    {
        return $this->morphMany(Riwayat::class, 'subjek');
    }

    /**
     * Employee-programme orders that still count (not rejected or cancelled).
     *
     * @param  Builder<LogOrder>  $query
     */
    #[Scope]
    protected function programKaryawan(Builder $query): void
    {
        $query->where('tipe_customer', TipeCustomer::Karyawan)
            ->whereNotIn('status_order', StatusOrder::batal());
    }

    /**
     * What Pesanan lists: an employee order only on the day it is made (after that it
     * is handled on Program karyawan), a finished or cancelled order only for 7 days.
     *
     * @param  Builder<LogOrder>  $query
     */
    #[Scope]
    protected function tampilDiPesanan(Builder $query): void
    {
        $query
            ->where(fn (Builder $q) => $q
                ->where('tipe_customer', '!=', TipeCustomer::Karyawan)
                ->orWhereNull('tipe_customer')
                ->orWhere('tanggal', '>=', now()->startOfDay()))
            ->where(fn (Builder $q) => $q
                ->whereIn('status_order', StatusOrder::berjalan())
                ->orWhere('tanggal', '>=', now()->subDays(7)->startOfDay()));
    }

    /**
     * Pesanan's order: running orders, then SELESAI, then cancelled; newest first in each.
     *
     * @param  Builder<LogOrder>  $query
     */
    #[Scope]
    protected function urutanPesanan(Builder $query): void
    {
        $berjalan = array_map(fn (StatusOrder $s) => $s->value, StatusOrder::berjalan());

        $query
            ->orderByRaw(
                'case when status_order in ('.implode(', ', array_fill(0, count($berjalan), '?')).') then 0 when status_order = ? then 1 else 2 end',
                [...$berjalan, StatusOrder::Selesai->value],
            )
            ->orderByDesc('tanggal');
    }

    /**
     * A transfer proof came in and the payment still has to be confirmed ("Bukti masuk").
     */
    public function buktiMenunggu(): bool
    {
        return $this->bukti_masuk_at !== null && $this->status_order === StatusOrder::Diacc;
    }

    /**
     * The customer's name, or the recipient's when the order has no CRM customer.
     */
    public function namaCustomer(): string
    {
        return $this->customer->nama ?? $this->nama_penerima ?? '—';
    }

    /**
     * "Jl. Kalpataru 18, Kel. Dinoyo, Kec. Lowokwaru, Kota Malang, Jawa Timur 65144"
     * (Orders.dc.html, OrderDetail.dc.html: Alamat).
     */
    public function alamat(): ?string
    {
        return $this->rangkaiAlamat([
            $this->alamat_lengkap,
            $this->kelurahan ? "Kel. {$this->kelurahan}" : null,
            $this->kecamatan ? "Kec. {$this->kecamatan}" : null,
            $this->kota,
            $this->provinsi,
        ]);
    }

    /**
     * The invoice's shorter form: "Jl. Kalpataru 18, Dinoyo, Lowokwaru, Kota Malang 65144".
     */
    public function alamatInvoice(): ?string
    {
        return $this->rangkaiAlamat([$this->alamat_lengkap, $this->kelurahan, $this->kecamatan, $this->kota]);
    }

    /**
     * The courier, or where the order is picked up ("· siap diambil" once it is ready).
     */
    public function kurir(): ?string
    {
        $kurir = $this->kurir_final ?? $this->kurir_pilihan;

        return $this->siap_diambil_at && $kurir ? "{$kurir} · siap diambil" : $kurir;
    }

    /**
     * How the list writes the payment method ("Transfer", "Bayar di tempat").
     */
    public function metodeSingkat(): ?string
    {
        return match ($this->metode_bayar) {
            'TRANSFER' => 'Transfer',
            'BAYAR DI TEMPAT (PICKUP)' => 'Bayar di tempat',
            'TRANSFER (PICKUP)' => 'Transfer · ambil sendiri',
            'POTONG GAJI' => 'Potong gaji',
            default => $this->metode_bayar,
        };
    }

    /**
     * The CONFIG_TOKO key for the time to pay after ACC; null when it is paid at pickup
     * or by salary.
     */
    public function kunciBatasBayar(): ?string
    {
        return match ($this->metode_bayar) {
            'BAYAR DI TEMPAT (PICKUP)', 'POTONG GAJI' => null,
            'TRANSFER (PICKUP)' => 'BATAS_BAYAR_PICKUP_JAM',
            default => 'BATAS_BAYAR_JAM',
        };
    }

    /**
     * Working hours to pay after ACC (null: no deadline).
     */
    public function jamBatasBayar(): ?int
    {
        $kunci = $this->kunciBatasBayar();

        return $kunci === null ? null : (int) PengaturanToko::nilai($kunci);
    }

    /**
     * "1,6 kg", "108 kg".
     */
    public function beratTeks(): ?string
    {
        return $this->berat_kg === null ? null : rtrim(rtrim(number_format((float) $this->berat_kg, 1, ',', '.'), '0'), ',').' kg';
    }

    /**
     * The non-empty parts joined by commas, the postcode after the last one.
     *
     * @param  list<string|null>  $bagian
     */
    private function rangkaiAlamat(array $bagian): ?string
    {
        $alamat = implode(', ', array_filter($bagian));

        return $alamat === '' ? null : trim("{$alamat} {$this->kode_pos}");
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal' => 'datetime',
            'status_order' => StatusOrder::class,
            'tipe_customer' => TipeCustomer::class,
            'total_produk' => 'integer',
            'ongkir' => 'integer',
            'berat_kg' => 'decimal:2',
            'ambil_sendiri' => 'boolean',
            'pesanan_tambahan' => 'boolean',
            'bukti_masuk_at' => 'datetime',
            'diacc_at' => 'datetime',
            'lunas_at' => 'datetime',
            'dikirim_at' => 'datetime',
            'siap_diambil_at' => 'datetime',
            'selesai_at' => 'datetime',
            'batas_bayar_at' => 'datetime',
            'diingatkan_at' => 'datetime',
        ];
    }

    /**
     * When the order moves on, the notification that asked someone to move it is done.
     * Here rather than in each command path, so the panel buttons, the bot, and demo
     * mode all close it the same way.
     */
    protected static function booted(): void
    {
        static::updated(function (LogOrder $order) {
            if (! $order->wasChanged('status_order')) {
                return;
            }

            $sebelum = StatusOrder::tryFrom((string) $order->getRawOriginal('status_order'));

            $selesai = array_values(array_filter([
                // ACC-ed, rejected, or cancelled: nobody needs to ACC it any more
                $sebelum === StatusOrder::MenungguKonfirmasi ? JenisNotifikasi::OrderBaru : null,
                // paid, or called off: the transfer proof no longer waits to be checked
                $sebelum === StatusOrder::Diacc ? JenisNotifikasi::BuktiMasuk : null,
                // an employee order handled by the PJ
                $sebelum === StatusOrder::Karyawan ? JenisNotifikasi::OrderKaryawan : null,
            ]));

            if ($selesai !== []) {
                Notifikasi::selesaikan($selesai, orderId: $order->id);
            }
        });
    }
}
