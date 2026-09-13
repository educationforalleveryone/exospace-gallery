<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
            'plan'              => 'free',
            'max_galleries'     => 1,
            'max_images'        => 10,
            'plan_started_at'   => now(),
            'plan_expires_at'   => null,
            'marketing_consent' => false,
            // Lifecycle email tracking columns
            'inactive_nudged_at'       => null,
            'plan_expiry_reminded_at'  => null,
            'google2fa_secret'  => null,
            'mfa_enabled_at'    => null,
            'mfa_backup_codes'  => null,
            // Subscription fields — null = no subscription (one-time purchase or free).
            'subscription_id'            => null,
            'subscription_status'        => null,
            'subscription_cancelled_at'  => null,
            'subscription_ends_at'       => null,
            // Dunning tracking — null = not in dunning window.
            'dunning_step'               => null,
            'dunning_last_sent_at'       => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

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

    public function withMfa(): static
    {
        return $this->state(fn (array $attributes) => [
            'google2fa_secret' => encrypt((new \PragmaRX\Google2FA\Google2FA())->generateSecretKey()),
            'mfa_enabled_at'   => now(),
        ]);
    }

    public function withSubscription(string $plan = 'pro'): static
    {
        return $this->state(fn (array $attributes) => [
            'plan'                    => $plan,
            'max_galleries'           => $plan === 'studio' ? 999 : 5,
            'max_images'              => $plan === 'studio' ? 500 : 100,
            'plan_started_at'         => now(),
            'plan_expires_at'         => now()->addMonth(),
            'subscription_id'         => 'SUB-' . \Illuminate\Support\Str::random(10),
            'subscription_status'     => 'active',
            'subscription_ends_at'    => now()->addMonth(),
            'subscription_cancelled_at' => null,
        ]);
    }

    public function withCancelledSubscription(string $plan = 'pro'): static
    {
        return $this->state(fn (array $attributes) => [
            'plan'                    => $plan,
            'max_galleries'           => $plan === 'studio' ? 999 : 5,
            'max_images'              => $plan === 'studio' ? 500 : 100,
            'plan_started_at'         => now()->subMonths(3),
            'plan_expires_at'         => now()->addDays(10), // 10 days left in paid period
            'subscription_id'         => 'SUB-' . \Illuminate\Support\Str::random(10),
            'subscription_status'     => 'cancelled',
            'subscription_ends_at'    => now()->addDays(10),
            'subscription_cancelled_at' => now()->subDays(2),
        ]);
    }
}
