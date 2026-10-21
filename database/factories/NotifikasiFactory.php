<?php

namespace Database\Factories;

use App\Enums\JenisNotifikasi;
use App\Models\Notifikasi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notifikasi>
 */
class NotifikasiFactory extends Factory
{
    /**
     * A notification already read, filed like the workflows file its type.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'waktu' => now(),
            'jenis' => JenisNotifikasi::LaporanHarian->value,
            'kategori' => JenisNotifikasi::LaporanHarian->kategori()->value,
            'penerima' => 'ADMIN_WA',
            'judul' => fake()->sentence(3),
            'detail' => fake()->sentence(),
            'dibaca_at' => fn (array $atribut) => $atribut['waktu'],
        ];
    }

    public function jenis(JenisNotifikasi $jenis): static
    {
        return $this->state(['jenis' => $jenis->value, 'kategori' => $jenis->kategori()->value]);
    }

    public function pada(string $waktu): static
    {
        return $this->state(['waktu' => $waktu]);
    }

    public function belumDibaca(): static
    {
        return $this->state(['dibaca_at' => null]);
    }
}
