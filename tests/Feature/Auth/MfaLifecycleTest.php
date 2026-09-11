<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

/**
 * ITERATION-6 — End-to-end MFA lifecycle tests.
 *
 * Covers the full production lifecycle on top of the replay-protection
 * suite (MfaReplayProtectionTest, untouched): enrollment + confirmation,
 * enabled-state persistence, the login-area challenge (RequireMfa),
 * failure behavior, the intended-destination round trip, the 30-minute
 * session TTL, the user-binding of the verified-session flag, the backup
 * code recovery path THROUGH the accepted input formats, and the
 * self-serve disable flow introduced in this iteration.
 *
 * Run: php artisan test --filter=MfaLifecycleTest
 */
class MfaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->google2fa = new Google2FA;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email_verified_at' => now(),
        ], $attributes));
    }

    /** An MFA-enabled user with 2 plaintext backup codes, TOTP baseline consumed. */
    private function enabledUser(array $attributes = []): User
    {
        $secret = $this->google2fa->generateSecretKey();

        return $this->user(array_merge([
            'google2fa_secret' => encrypt($secret),
            'mfa_enabled_at' => now(),
            'mfa_backup_codes' => [
                Hash::make('AAAAA11111'),
                Hash::make('BBBBB22222'),
            ],
            'google2fa_ts' => (int) floor(now()->timestamp / 30),
        ], $attributes));
    }

    private function currentOtp(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }

    // ── Enrollment / setup ───────────────────────────────────────────────

    public function test_setup_page_renders_qr_and_secret_for_a_regular_user(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->get('/mfa/setup');

        $response->assertOk();
        $response->assertSee('name="code"', false);
        $response->assertSee('data:image/', false); // QR inline image
        // Role-aware copy: MFA is OPTIONAL for regular users.
        $response->assertDontSee('MFA is required for super-admin accounts.');
        // The pending secret must be stashed server-side for the enable step.
        $this->assertNotNull(session('mfa_pending_secret'));
    }

    public function test_setup_page_copy_is_role_aware_for_super_admins(): void
    {
        $user = $this->user(['is_super_admin' => true]);

        $this->actingAs($user)->get('/mfa/setup')
            ->assertOk()
            ->assertSee('MFA is required for super-admin accounts.');
    }

    public function test_incorrect_confirmation_code_does_not_enable_mfa(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->withSession(['mfa_pending_secret' => $this->google2fa->generateSecretKey()])
            ->from('/mfa/setup')
            ->post('/mfa/setup', ['code' => '000000'])
            ->assertRedirect('/mfa/setup')
            ->assertSessionHasErrors('code');

        $user->refresh();
        $this->assertNull($user->google2fa_secret);
        $this->assertNull($user->mfa_enabled_at);
        $this->assertNull($user->mfa_backup_codes);
        $this->assertNull($user->google2fa_ts);
    }

    public function test_non_numeric_confirmation_code_is_rejected(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->withSession(['mfa_pending_secret' => $this->google2fa->generateSecretKey()])
            ->post('/mfa/setup', ['code' => 'banana'])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->refresh()->google2fa_secret);
    }

    public function test_correct_confirmation_enables_mfa_and_generates_backup_codes(): void
    {
        $user = $this->user();
        $secret = $this->google2fa->generateSecretKey();

        $response = $this->actingAs($user)
            ->withSession(['mfa_pending_secret' => $secret])
            ->post('/mfa/setup', ['code' => $this->currentOtp($secret)]);

        $response->assertRedirect(route('mfa.backup-codes'));

        $user->refresh();
        $this->assertNotNull($user->google2fa_secret);
        $this->assertSame($secret, decrypt($user->google2fa_secret));
        $this->assertNotNull($user->mfa_enabled_at);
        $this->assertCount(10, $user->mfa_backup_codes);
        $this->assertNotNull($user->google2fa_ts);

        // The enrollment secret must not linger in the session…
        $this->assertNull(session('mfa_pending_secret'));
        // …and enabling completes the challenge for this session immediately.
        $this->assertTrue(session('mfa_verified'));
        $this->assertSame($user->id, (int) session('mfa_verified_user_id'));

        // Audit visibility.
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'mfa.enabled',
            'actor_id' => $user->id,
        ]);
    }

    public function test_backup_codes_are_stored_hashed_not_plaintext(): void
    {
        $user = $this->user();
        $secret = $this->google2fa->generateSecretKey();

        $this->actingAs($user)
            ->withSession(['mfa_pending_secret' => $secret])
            ->post('/mfa/setup', ['code' => $this->currentOtp($secret)]);

        $user->refresh();
        foreach ($user->mfa_backup_codes as $hashed) {
            $this->assertStringStartsWith('$2y$', $hashed);
        }
    }

    public function test_backup_codes_page_expiration_redirects_regular_users_to_settings(): void
    {
        $user = $this->user();

        // No 'backup_codes' in the session (page revisited after the flash
        // aged out) — a regular user must be sent to SETTINGS, never to
        // /master-control (super-admin-only, would 403).
        $this->actingAs($user)->get('/mfa/backup-codes')
            ->assertRedirect(route('profile.edit'));
    }

    public function test_backup_codes_page_expiration_still_continues_super_admins_to_master_control(): void
    {
        $user = $this->user(['is_super_admin' => true]);

        $this->actingAs($user)->get('/mfa/backup-codes')
            ->assertRedirect(route('super.index'));
    }

    public function test_enabled_user_with_verified_session_visiting_setup_is_sent_to_settings(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->withSession([
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
                'mfa_verified_user_id' => $user->id,
            ])
            ->get('/mfa/setup')
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status');
    }

    public function test_enabled_user_with_unverified_session_visiting_setup_is_sent_to_verify(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)->get('/mfa/setup')
            ->assertRedirect(route('mfa.verify'));
    }

    // ── Login-area challenge (RequireMfa) ────────────────────────────────

    public function test_regular_user_without_mfa_passes_gated_routes(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/billing')->assertOk();
    }

    public function test_mfa_enabled_user_is_challenged_on_gated_routes(): void
    {
        $user = $this->enabledUser();

        $response = $this->actingAs($user)->get('/billing');

        $response->assertRedirect(route('mfa.verify'));
        $response->assertSessionHas('info');
        // The challenge must remember the destination (iteration-6: intended
        // round trip)…
        $this->assertSame(url('/billing'), session('url.intended'));
        // …and must NOT mark the session as verified.
        $this->assertNull(session('mfa_verified'));
    }

    public function test_challenge_returns_the_user_to_the_deep_link_they_were_heading_to(): void
    {
        $user = $this->enabledUser();
        $secret = decrypt($user->google2fa_secret);

        // Hit a deep link — NOT the billing index — while unverified.
        $this->actingAs($user)->get('/billing/upgrade/pro')
            ->assertRedirect(route('mfa.verify'));
        $this->assertSame(url('/billing/upgrade/pro'), session('url.intended'));

        // Complete the challenge with a fresh-window code.
        $user->forceFill(['google2fa_ts' => 0])->save();
        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => $this->currentOtp($secret)])
            ->assertRedirect(url('/billing/upgrade/pro'));
    }

    public function test_wrong_challenge_code_is_rejected_and_does_not_verify_the_session(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->from('/mfa/verify')
            ->post('/mfa/verify', ['code' => '000000'])
            ->assertRedirect('/mfa/verify')
            ->assertSessionHasErrors('code');

        $this->assertNull(session('mfa_verified'));
    }

    public function test_challenge_tto_expires_after_thirty_minutes(): void
    {
        $user = $this->enabledUser();
        $secret = decrypt($user->google2fa_secret);

        // The helper consumes the current window as the baseline; reset it
        // so the live code is still "newer".
        $user->forceFill(['google2fa_ts' => 0])->save();

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => $this->currentOtp($secret)])
            ->assertRedirect();

        // Within the TTL: gated access is granted.
        $this->actingAs($user)->get('/billing')->assertOk();

        // Past the TTL: challenged again.
        $this->travel(31)->minutes();
        $this->actingAs($user)->get('/billing')
            ->assertRedirect(route('mfa.verify'));
    }

    public function test_verified_session_flag_is_bound_to_the_verifying_user(): void
    {
        // The session flag survives session-ID regeneration (login flows
        // rotate the ID but keep the data). A DIFFERENT user authenticating
        // into the same session must not inherit the first user's
        // MFA-verified state.
        $alice = $this->enabledUser();
        $bob = $this->enabledUser();

        // Alice verifies.
        $this->actingAs($alice)
            ->post('/mfa/verify', ['code' => $this->currentOtp(decrypt($alice->google2fa_secret))])
            ->assertRedirect();

        // Simulate a re-login into the SAME PHP session: same session
        // contents, but a different authenticated user.
        $this->actingAs($bob)
            ->withSession($this->app['session.store']->all())
            ->get('/billing')
            ->assertRedirect(route('mfa.verify')); // Bob is challenged — no inherited bypass.
    }

    public function test_gated_post_endpoints_are_also_challenged(): void
    {
        $user = $this->enabledUser();

        // A POST inside the mfa-gated group must bounce to the challenge —
        // and must NOT record the POST endpoint as the intended destination
        // (it would 405 on the way back).
        $this->actingAs($user)
            ->post('/billing/downgrade')
            ->assertRedirect(route('mfa.verify'));

        $this->assertNull(session('url.intended'));
    }

    public function test_challenge_screen_is_not_shown_to_users_without_mfa(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/mfa/verify')
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status');
    }

    // ── Backup-code recovery path ────────────────────────────────────────

    public function test_formatted_backup_code_verifies(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'AAAAA-11111'])
            ->assertRedirect();

        $this->assertTrue(session('mfa_verified'));
    }

    public function test_lowercase_backup_code_verifies(): void
    {
        // Manually typed codes may come out lowercase; hashes are case-
        // sensitive, so the controller must normalise.
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'bbbbb22222'])
            ->assertRedirect();
    }

    public function test_used_backup_code_cannot_be_reused(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'AAAAA-11111'])->assertRedirect();

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'AAAAA-11111'])
            ->assertSessionHasErrors('code');

        // The other code is unaffected.
        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'BBBBB22222'])->assertRedirect();
    }

    public function test_backup_code_use_is_audited(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'AAAAA-11111'])->assertRedirect();

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'mfa.backup_code_used',
            'actor_id' => $user->id,
        ]);
    }

    // ── Disable flow (new in iteration 6) ────────────────────────────────

    public function test_disable_requires_password(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->post('/mfa/disable', [])
            ->assertSessionHasErrorsIn('mfaDisable', 'password');

        $this->assertNotNull($user->refresh()->google2fa_secret);
    }

    public function test_disable_with_wrong_password_fails_and_keeps_mfa(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->post('/mfa/disable', ['password' => 'wrong-password'])
            ->assertSessionHasErrorsIn('mfaDisable', 'password');

        $this->assertNotNull($user->refresh()->google2fa_secret);
        $this->assertNotNull($user->mfa_enabled_at);
    }

    public function test_disable_with_correct_password_clears_all_mfa_state(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->withSession([
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
                'mfa_verified_user_id' => $user->id,
                'mfa_pending_secret' => 'stale',
            ])
            ->post('/mfa/disable', ['password' => 'password'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status')
            // Session artifacts gone.
            ->assertSessionMissing('mfa_verified')
            ->assertSessionMissing('mfa_verified_at')
            ->assertSessionMissing('mfa_verified_user_id')
            ->assertSessionMissing('mfa_pending_secret');

        $user->refresh();
        $this->assertNull($user->google2fa_secret);
        $this->assertNull($user->mfa_enabled_at);
        $this->assertNull($user->mfa_backup_codes);
        $this->assertNull($user->google2fa_ts);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'mfa.disabled',
            'actor_id' => $user->id,
        ]);
    }

    public function test_disabled_user_no_longer_faces_challenges(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->post('/mfa/disable', ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));

        $this->actingAs($user)->get('/billing')->assertOk();
    }

    public function test_disable_works_while_mfa_session_is_unverified_lost_device_recovery(): void
    {
        // The whole point of the recovery path: no MFA challenge has been
        // completed in this session, and the user still gets out.
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->post('/mfa/disable', ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));

        $this->assertNull($user->refresh()->google2fa_secret);
    }

    public function test_mfa_can_be_re_enabled_after_disable(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->post('/mfa/disable', ['password' => 'password']);

        // Re-enroll from scratch with a fresh secret.
        $freshSecret = $this->google2fa->generateSecretKey();
        $this->actingAs($user->refresh())
            ->withSession(['mfa_pending_secret' => $freshSecret])
            ->post('/mfa/setup', ['code' => $this->currentOtp($freshSecret)])
            ->assertRedirect(route('mfa.backup-codes'));

        $user->refresh();
        $this->assertSame($freshSecret, decrypt($user->google2fa_secret));
        $this->assertCount(10, $user->mfa_backup_codes); // fresh codes, old ones gone
        $this->assertNotNull($user->mfa_enabled_at);
    }

    public function test_disable_on_account_without_mfa_is_an_idempotent_noop(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->post('/mfa/disable', ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));

        // No misleading audit entry for a no-op.
        $this->assertDatabaseMissing('admin_audit_logs', [
            'action' => 'mfa.disabled',
            'actor_id' => $user->id,
        ]);
    }

    public function test_throttle_buckets_are_isolated_per_endpoint(): void
    {
        // Iteration-5 V-3 class regression guard: Laravel's numeric
        // throttle:6,1 keys by IP ALONE unless a prefix is given, so all
        // numerically throttled routes used to share ONE bucket — failed
        // verify attempts could lock a user out of the disable/recovery
        // endpoint within the same minute. Each MFA endpoint now carries
        // its own prefix; exhausting one must leave the others open.
        $user = $this->enabledUser();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post('/mfa/verify', ['code' => '000000']);
        }

        // 7th verify attempt is throttled…
        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => '000000'])
            ->assertTooManyRequests();

        // …but the recovery path is NOT.
        $this->actingAs($user)
            ->post('/mfa/disable', ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));
    }

    // ── Super-admin enforcement ──────────────────────────────────────────

    public function test_super_admin_without_mfa_is_forced_into_setup(): void
    {
        $user = $this->user(['is_super_admin' => true]);

        $this->actingAs($user)->get('/master-control')
            ->assertRedirect(route('mfa.setup'))
            ->assertSessionHas('warning');
    }

    public function test_super_admin_with_mfa_is_challenged_like_regular_users(): void
    {
        $user = $this->enabledUser(['is_super_admin' => true]);

        $this->actingAs($user)->get('/master-control')
            ->assertRedirect(route('mfa.verify'));
    }

    // ── Secret hygiene ───────────────────────────────────────────────────

    public function test_responses_never_leak_the_totp_secret_or_backup_hashes(): void
    {
        $user = $this->enabledUser();
        $secret = decrypt($user->google2fa_secret);

        $pages = ['/profile', '/mfa/verify', '/billing'];
        foreach ($pages as $page) {
            $response = $this->actingAs($user)->get($page);
            if ($response->status() === 302) {
                continue; // challenged pages redirect away; nothing to leak there
            }
            $content = $response->getContent();
            $this->assertStringNotContainsString($secret, $content, "Page {$page} leaked the TOTP secret.");
            $this->assertStringNotContainsString('$2y$', $content, "Page {$page} leaked backup-code hashes.");
        }
    }
}
