<?php

namespace Database\Factories;

use App\Models\PendingUpgrade;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PendingUpgradeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'    => User::factory(),
            'token'      => PendingUpgrade::hashToken(PendingUpgrade::generateToken()),
            'plan'       => fake()->randomElement(['pro', 'studio']),
            'product_id' => (string) fake()->numberBetween(1000, 9999),
            'status'     => 'pending',
            'expires_at' => now()->addDays(7),
        ];
    }
}
