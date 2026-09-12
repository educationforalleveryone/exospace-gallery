<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TeamInvitationFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (\App\Models\TeamInvitation $invitation) {
            $token = $invitation->token;
            if ($token !== null && ! preg_match('/^[a-f0-9]{64}$/', $token)) {
                $invitation->token = hash('sha256', $token);
            }
        });
    }

    public function definition(): array
    {
        $plaintext = Str::random(64);

        return [
            'team_id'   => Team::factory(),
            'email'     => fake()->safeEmail(),
            'token'     => hash('sha256', $plaintext),
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

    public function withToken(string $plaintext): static
    {
        return $this->state(fn () => [
            'token' => hash('sha256', $plaintext),
        ]);
    }
}
