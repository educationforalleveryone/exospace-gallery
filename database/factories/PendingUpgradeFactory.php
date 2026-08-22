<?php

namespace Database\Factories;

use App\Models\PendingUpgrade;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PendingUpgrade>
 *
 * AUDIT-P1-8.1: Factory now stores the HASHED token (matching the model's
 * createForUser behavior). Tests that need the plaintext token for webhook
 * payloads should use PendingUpgrade::createForUser() (which attaches the
 * plaintext_token runtime attribute) instead of the factory.
 */
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
