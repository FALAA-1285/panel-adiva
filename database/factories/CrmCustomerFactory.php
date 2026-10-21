<?php

namespace Database\Factories;

use App\Models\CrmCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmCustomer>
 */
class CrmCustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nomor = fake()->unique()->numerify('628##########');

        return [
            'chat_id' => $nomor.'@c.us',
            'nomor_wa' => $nomor,
            'nama' => fake()->name(),
        ];
    }
}
