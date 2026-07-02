<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PendingUpgrade>
 */
class PendingUpgradeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'    => User::factory(),
            'token'      => Str::random(48),
            'plan'       => fake()->randomElement(['pro', 'studio']),
            'product_id' => (string) fake()->numberBetween(1000, 9999),
            'status'     => 'pending',
            'expires_at' => now()->addDays(7),
        ];
    }
}
