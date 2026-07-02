<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => static::$password ??= Hash::make('password'),
            'remember_token'    => Str::random(10),
            // Plan fields — the User model's boot() creating hook sets
            // these if not provided, but being explicit makes tests clearer.
            'plan'              => 'free',
            'max_galleries'     => 1,
            'max_images'        => 10,
            'plan_started_at'   => now(),
            'plan_expires_at'   => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    // ── Plan states (Task H14) ─────────────────────────────────────────

    public function pro(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan'           => 'pro',
            'max_galleries'  => 5,
            'max_images'     => 100,
        ]);
    }

    public function studio(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan'           => 'studio',
            'max_galleries'  => 999,
            'max_images'     => 500,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_super_admin' => true,
        ]);
    }

    public function banned(string $reason = 'Banned for testing'): static
    {
        return $this->state(fn (array $attributes) => [
            'banned_at'  => now(),
            'ban_reason' => $reason,
        ]);
    }
}
