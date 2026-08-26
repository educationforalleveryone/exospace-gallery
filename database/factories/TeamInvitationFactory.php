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
    public function configure(): static
    {
        return $this->afterMaking(function (\App\Models\TeamInvitation $invitation) {
            // ITERATION-1 FIX: tests commonly pass a readable plaintext like
            // ->create(['token' => 'accept-123']). A stored sha256 hash is
            // always 64 lowercase hex chars; anything else is a plaintext
            // value that must be hashed so findByToken() can resolve it.
            $token = $invitation->token;
            if ($token !== null && ! preg_match('/^[a-f0-9]{64}$/', $token)) {
                $invitation->token = hash('sha256', $token);
            }
        });
    }

    public function definition(): array
    {
        // ITERATION-1 FIX: the token column stores a SHA-256 HASH (D-6 —
        // TeamInvitation::findByToken() hashes the plaintext before lookup).
        // The old factory stored a raw random string, which could never be
        // found via findByToken(): every feature test that created an
        // invitation and then hit /team-invitations/{token} got a 404.
        //
        // Tests that need the plaintext for URL building should pass an
        // explicit 'token' state value — it is hashed automatically here —
        // e.g. TeamInvitation::factory()->create(['token' => 'accept-123']).
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

    /**
     * Hash an explicitly provided plaintext token so it round-trips
     * through findByToken(). Invoked automatically for state values.
     */
    public function withToken(string $plaintext): static
    {
        return $this->state(fn () => [
            'token' => hash('sha256', $plaintext),
        ]);
    }
}
