<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $nama_produk
 * @property string|null $keterangan_harga
 * @property int $qty
 * @property int $harga_satuan
 * @property int $subtotal
 */
#[Table('order_items')]
#[Fillable([
    'log_order_id', 'produk_id', 'paket_reseller_id', 'urutan', 'nama_produk',
    'keterangan_harga', 'qty', 'harga_satuan', 'subtotal',
])]
class OrderItem extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * Employee prices are named as such (Orders.dc.html: "Basreng Original (harga karyawan)").
     */
    public function namaTampil(): string
    {
        return $this->keterangan_harga === 'Harga karyawan' ? "{$this->nama_produk} (harga karyawan)" : $this->nama_produk;
    }

    /**
     * @return BelongsTo<LogOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(LogOrder::class, 'log_order_id');
    }

    /**
     * @return BelongsTo<Produk, $this>
     */
    public function produk(): BelongsTo
    {
        return $this->belongsTo(Produk::class);
    }

    /**
     * @return BelongsTo<PaketReseller, $this>
     */
    public function paket(): BelongsTo
    {
        return $this->belongsTo(PaketReseller::class, 'paket_reseller_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'harga_satuan' => 'integer',
            'subtotal' => 'integer',
        ];
    }
}
