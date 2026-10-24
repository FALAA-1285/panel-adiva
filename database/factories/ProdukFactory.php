<?php

namespace Database\Factories;

use App\Models\Produk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Produk>
 */
class ProdukFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'jenis_produk' => fake()->unique()->words(2, true),
            'kategori_produk' => fake()->word(),
            'kategori_kemasan' => 'Pack',
            'gramasi' => fake()->numberBetween(100, 500).' g',
            'rasa' => fake()->word(),
            'target_pasar' => fake()->words(2, true),
        ];
    }

    /**
     * Katalog.dc.html, first row: tier prices not filled in yet.
     */
    public function molreng(): static
    {
        return $this->state([
            'jenis_produk' => 'Molreng Reguler',
            'kategori_produk' => 'Molreng',
            'kategori_kemasan' => 'Pack',
            'gramasi' => '250 g',
            'rasa' => 'Bawang original, pedas',
            'target_pasar' => 'Kantin sekolah, warung',
            'harga_bertingkat' => [
                'satuan' => 'pcs',
                'tier' => [
                    ['min' => 1, 'max' => 9, 'harga' => null],
                    ['min' => 10, 'max' => 49, 'harga' => null],
                    ['min' => 50, 'max' => null, 'harga' => null],
                ],
            ],
        ]);
    }
}
