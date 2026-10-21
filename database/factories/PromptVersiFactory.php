<?php

namespace Database\Factories;

use App\Enums\StatusPrompt;
use App\Models\PromptVersi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromptVersi>
 */
class PromptVersiFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'versi' => fake()->unique()->numberBetween(1, 999),
            'ringkasan' => fake()->sentence(4),
            'bagian' => [
                ['no' => 0, 'judul' => 'Baca dulu', 'teks' => 'Baca semua bagian.'],
                ['no' => 1, 'judul' => 'Identitas', 'teks' => 'Kamu Adiva.'],
            ],
            'status' => StatusPrompt::Arsip,
            'diterbitkan_at' => now()->subWeek(),
        ];
    }

    public function aktif(): static
    {
        return $this->state(['status' => StatusPrompt::Aktif]);
    }

    public function draf(): static
    {
        return $this->state(['status' => StatusPrompt::Draf, 'diterbitkan_at' => null]);
    }
}
