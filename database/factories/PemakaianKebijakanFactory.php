<?php

namespace Database\Factories;

use App\Models\Kebijakan;
use App\Models\PemakaianKebijakan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PemakaianKebijakan>
 */
class PemakaianKebijakanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kebijakan_id' => Kebijakan::factory(),
            'waktu' => now(),
        ];
    }

    public function pada(string $waktu): static
    {
        return $this->state(['waktu' => $waktu]);
    }
}
