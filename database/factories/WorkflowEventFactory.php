<?php

namespace Database\Factories;

use App\Models\Workflow;
use App\Models\WorkflowEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowEvent>
 */
class WorkflowEventFactory extends Factory
{
    /**
     * A successful run.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'waktu' => now(),
            'jenis' => 'EKSEKUSI',
            'status' => 'BERHASIL',
            'judul' => fake()->sentence(3),
        ];
    }

    public function pada(string $waktu): static
    {
        return $this->state(['waktu' => $waktu]);
    }

    public function gagal(): static
    {
        return $this->state(['status' => 'GAGAL']);
    }
}
