<?php

namespace Database\Factories;

use App\Enums\StatusOrder;
use App\Enums\TipeCustomer;
use App\Models\LogOrder;
use App\Models\PaketReseller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogOrder>
 */
class LogOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => fake()->unique()->numerify('ORD-######-###'),
            'nama_penerima' => fake()->name(),
            'tanggal' => now(),
            'status_order' => StatusOrder::MenungguKonfirmasi,
            'total_produk' => 0,
            'ongkir' => 0,
        ];
    }

    public function pada(string $tanggal): static
    {
        return $this->state(['tanggal' => $tanggal]);
    }

    public function status(StatusOrder $status): static
    {
        return $this->state(['status_order' => $status]);
    }

    /**
     * An order from a verified employee's number, paid by salary deduction
     * (Orders.dc.html: ORD-260928-011).
     */
    public function karyawan(string $nomor): static
    {
        return $this->state([
            'nomor_wa' => $nomor,
            'tipe_customer' => TipeCustomer::Karyawan,
            'metode_bayar' => 'POTONG GAJI',
            'status_order' => StatusOrder::Karyawan,
        ]);
    }

    /**
     * One package line (Paket.dc.html: "Terjual 14× bulan ini").
     */
    public function denganPaket(PaketReseller $paket, int $qty): static
    {
        return $this->afterCreating(fn (LogOrder $order) => $order->items()->create([
            'urutan' => 1,
            'paket_reseller_id' => $paket->id,
            'nama_produk' => $paket->nama_paket,
            'qty' => $qty,
            'harga_satuan' => 0,
            'subtotal' => 0,
        ]));
    }
}
