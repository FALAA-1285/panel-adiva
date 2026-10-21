<?php

namespace Database\Factories;

use App\Enums\StatusData;
use App\Models\Kebijakan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Kebijakan>
 */
class KebijakanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode' => fake()->unique()->numerify('KB-###'),
            'topik' => fake()->words(2, true),
            'status_data' => StatusData::Aktif->value,
        ];
    }

    /**
     * Kebijakan.dc.html: the policy open in the editor.
     */
    public function ongkir(): static
    {
        return $this->state([
            'kode' => 'KB-03',
            'topik' => 'Ongkir & ekspedisi',
            'jawaban_resmi' => 'Kirim dari gudang Sawojajar.',
            'kata_pemicu' => 'ongkir, kirim, JNE',
        ]);
    }
}
