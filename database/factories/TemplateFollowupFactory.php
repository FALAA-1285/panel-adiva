<?php

namespace Database\Factories;

use App\Models\TemplateFollowup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemplateFollowup>
 */
class TemplateFollowupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode' => fake()->unique()->numerify('FU-0#'),
            'judul' => fake()->sentence(3),
            'tahap' => 'BARU',
            'acuan_waktu' => 'CHAT_TERAKHIR',
            'pesan' => "Halo kak {nama}\n".fake()->sentence(),
            'status_aktif' => true,
        ];
    }
}
