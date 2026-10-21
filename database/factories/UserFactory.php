<?php

namespace Database\Factories;

use App\Enums\Peran;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'peran' => Peran::StafCs,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user is a supervisor.
     */
    public function supervisor(): static
    {
        return $this->state(fn (array $attributes) => [
            'peran' => Peran::Supervisor,
        ]);
    }

    /**
     * Falah, the supervisor in design/source (LupaSandi.dc.html: "+62 812-••••-••07").
     */
    public function falah(): static
    {
        return $this->supervisor()->state([
            'name' => 'Falah',
            'email' => 'falah@bangbana.id',
            'nomor_wa' => '6281234560007',
        ]);
    }

    /**
     * Dina, staf CS who is on the list but has no panel password yet
     * (Daftar.dc.html: "+62 857-••••-••36").
     */
    public function dina(): static
    {
        return $this->state([
            'name' => 'Dina',
            'email' => 'dina@bangbana.id',
            'nomor_wa' => '6285712340036',
            'peran' => Peran::StafCs,
            'password' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
