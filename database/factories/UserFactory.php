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
            // P0-3: marketing consent defaults to false (opt-in required).
            // Tests that need consented users should use ->create(['marketing_consent' => true]).
            'marketing_consent' => false,
            // P0-7: lifecycle email tracking columns (split from lifecycle_nudged_at)
            'inactive_nudged_at'       => null,
            'plan_expiry_reminded_at'  => null,
            // TD-17: MFA fields (P3-7/P3-8) — null = MFA not enabled.
            // Tests that need MFA-enabled users should use the withMfa() state.
            'google2fa_secret'  => null,
            'mfa_enabled_at'    => null,
            'mfa_backup_codes'  => null,
            // M-1: Subscription fields — null = no subscription (one-time purchase or free).
            'subscription_id'            => null,
            'subscription_status'        => null,
            'subscription_cancelled_at'  => null,
            'subscription_ends_at'       => null,
            // M-9: Dunning tracking — null = not in dunning window.
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

    /**
     * TD-17: MFA-enabled state. Sets a fake (but valid-format) TOTP secret
     * + enabled timestamp. The secret is encrypted the same way the
     * MfaController does it (encrypt() with APP_KEY).
     *
     * Tests that need to verify MFA flows (setup, verify, backup codes)
     * should use a real pragmarx/google2fa secret instead of this state —
     * this state is for tests that just need the user to HAVE MFA enabled
     * (e.g. testing the RequireMfa middleware gating).
     */
    public function withMfa(): static
    {
        return $this->state(fn (array $attributes) => [
            // ITERATION-1 FIX: generateSecretKey() is an instance method
            // in google2fa v8 — calling it statically threw a fatal Error
            // whenever the withMfa() state was used.
            'google2fa_secret' => encrypt((new \PragmaRX\Google2FA\Google2FA())->generateSecretKey()),
            'mfa_enabled_at'   => now(),
        ]);
    }

    /**
     * M-1: Active subscription state. Sets a fake subscription_id + active
     * status + subscription_ends_at 30 days from now (monthly billing).
     * For tests that need users with active recurring subscriptions.
     */
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

    /**
     * M-1: Cancelled subscription state (still within paid period).
     */
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
