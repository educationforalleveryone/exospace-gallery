<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TeamInvitation>
 */
class TeamInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id'   => Team::factory(),
            'email'     => fake()->safeEmail(),
            'token'     => Str::random(64),
            'role'      => fake()->randomElement(['editor', 'viewer']),
            'expires_at'=> now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
