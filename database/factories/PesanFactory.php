<?php

namespace Database\Factories;

use App\Enums\PengirimPesan;
use App\Models\GambarProduk;
use App\Models\Pesan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pesan>
 */
class PesanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_id' => fake()->numerify('628##########').'@c.us',
            'waktu' => now(),
            'pengirim' => PengirimPesan::Adiva,
        ];
    }

    public function pada(string $waktu): static
    {
        return $this->state(['waktu' => $waktu]);
    }

    /**
     * A product photo sent in the chat (Gambar.dc.html: "Dikirim 42× bulan ini").
     */
    public function fotoProduk(GambarProduk $gambar): static
    {
        return $this->state(['gambar_produk_id' => $gambar->id]);
    }
}
