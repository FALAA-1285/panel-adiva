<?php

namespace Database\Factories;

use App\Enums\StatusWorkflow;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode' => fake()->unique()->slug(2),
            'nama' => fake()->words(3, true),
            'jumlah_node' => fake()->numberBetween(5, 40),
            'jadwal' => 'Tiap jam',
            'deskripsi' => fake()->sentence(),
            'status' => StatusWorkflow::Aktif->value,
        ];
    }
}
