<?php

namespace Database\Factories;

use App\Enums\StatusData;
use App\Models\PaketReseller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaketReseller>
 */
class PaketResellerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode_paket' => fake()->unique()->bothify('PKT-??##'),
            'nama_paket' => 'Paket '.fake()->word(),
            'isi' => fake()->sentence(3),
            'status_data' => StatusData::Aktif->value,
        ];
    }

    /**
     * Paket.dc.html: the package open in the editor.
     */
    public function kantin(): static
    {
        return $this->state([
            'kode_paket' => 'PKT-A',
            'nama_paket' => 'Paket Kantin',
            'isi' => 'Molreng 20 + Basreng 20',
            'isi_detail' => [['nama' => 'Molreng Reguler Bawang Original', 'jumlah' => '10 pcs']],
            'cocok_untuk' => 'Kantin sekolah, modal kecil',
            'berat' => '± 1 kg',
        ]);
    }
}
