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
            'google2fa_secret' => encrypt(\PragmaRX\Google2FA\Google2FA::generateSecretKey()),
            'mfa_enabled_at'   => now(),
        ]);
    }
}
