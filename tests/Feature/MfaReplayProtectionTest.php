<?php

declare(strict_types=1);

/**
 * ITERATION-3 — TOTP replay-window protection tests.
 *
 * MfaController previously used Google2FA::verifyKey(), which has no
 * memory: the same six-digit code authenticated an unlimited number of
 * times inside its 30-second slice (up to ~90s with the library's ±1
 * drift window). Both entry points now use verifyKeyNewer() with the
 * last accepted OTP counter persisted on users.google2fa_ts.
 *
 * Covered:
 *   - a code that just verified successfully is REJECTED when replayed;
 *   - the next time-slice's code still works (drift tolerance preserved);
 *   - the counter baseline is persisted on each success (null → stamped);
 *   - the setup code used to ENABLE MFA cannot be replayed on /mfa/verify;
 *   - backup codes keep working alongside (single-use by consumption).
 *
 * Run: php artisan test --filter=MfaReplayProtectionTest
 */

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

class MfaReplayProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->google2fa = new Google2FA();
    }

    private function mfaUser(string $secret, ?int $lastUsed = null): User
    {
        return User::factory()->create([
            'google2fa_secret' => encrypt($secret),
            'google2fa_ts'     => $lastUsed,
        ]);
    }

    public function test_valid_code_verifies_and_stamps_the_replay_baseline(): void
    {
        $secret = $this->google2fa->generateSecretKey();
        $user = $this->mfaUser($secret);

        $response = $this->actingAs($user)->post('/mfa/verify', [
            'code' => $this->google2fa->getCurrentOtp($secret),
        ]);

        $response->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->google2fa_ts, 'Success must persist the OTP counter baseline.');
        $this->assertGreaterThan(0, (int) $user->google2fa_ts);
    }

    public function test_replayed_code_is_rejected(): void
    {
        $secret = $this->google2fa->generateSecretKey();
        $user = $this->mfaUser($secret);

        $code = $this->google2fa->getCurrentOtp($secret);

        // First use: accepted.
        $this->actingAs($user)->post('/mfa/verify', ['code' => $code])->assertRedirect();
        $stampedCounter = (int) $user->refresh()->google2fa_ts;
        $this->assertGreaterThan(0, $stampedCounter);

        // Replay of the SAME code: rejected with the generic error (no
        // oracle telling an attacker the code was valid-but-used).
        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => $code])
            ->assertSessionHasErrors('code');

        // Baseline unchanged — a failed attempt must never move it.
        $this->assertSame($stampedCounter, (int) $user->refresh()->google2fa_ts);
    }

    public function test_next_window_code_still_accepted_after_replay_protection(): void
    {
        // Drift tolerance must survive: the fix rejects USED codes, not
        // fresh ones from the adjacent slice a slow-typing user lands in.
        $secret = $this->google2fa->generateSecretKey();
        $user = $this->mfaUser($secret);

        $currentCounter = (int) floor(now()->timestamp / 30);
        $currentCode = $this->google2fa->oathTotp($secret, $currentCounter);

        $this->actingAs($user)->post('/mfa/verify', ['code' => $currentCode])->assertRedirect();

        $nextCode = $this->google2fa->oathTotp($secret, $currentCounter + 1);
        $this->actingAs($user)->post('/mfa/verify', ['code' => $nextCode])->assertRedirect();

        $this->assertSame($currentCounter + 1, (int) $user->refresh()->google2fa_ts);
    }

    public function test_old_window_code_is_rejected_after_use(): void
    {
        // A code from a PAST slice was never valid to begin with once a
        // newer one was used — but make the baseline semantics explicit:
        // after using slice C's code, slice C-1's code must fail even
        // though the library's window would normally still cover it.
        $secret = $this->google2fa->generateSecretKey();
        $user = $this->mfaUser($secret);

        $currentCounter = (int) floor(now()->timestamp / 30);

        // Seed the baseline as if slice C's code was just used.
        $user->forceFill(['google2fa_ts' => $currentCounter])->save();

        $previousCode = $this->google2fa->oathTotp($secret, $currentCounter - 1);
        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => $previousCode])
            ->assertSessionHasErrors('code');
    }

    public function test_setup_code_cannot_be_replayed_on_verify_screen(): void
    {
        // The enable path stamps google2fa_ts at enable time — the very
        // code that activated MFA must not open a second session.
        $secret = $this->google2fa->generateSecretKey();
        $user = User::factory()->create();

        $code = $this->google2fa->getCurrentOtp($secret);

        $this->actingAs($user)
            ->withSession(['mfa_pending_secret' => $secret])
            ->post('/mfa/setup', ['code' => $code])
            ->assertRedirect(route('mfa.backup-codes'));

        $user->refresh();
        $this->assertNotNull($user->google2fa_secret);
        $this->assertNotNull($user->google2fa_ts, 'Enable must stamp the replay baseline.');

        // Same code on the verify screen: rejected.
        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => $code])
            ->assertSessionHasErrors('code');
    }

    public function test_backup_code_still_works_after_totp_path_changes(): void
    {
        // Symmetry: the backup-code path (single-use by consumption since
        // P3-7) must keep working — the TOTP hardening must not break it.
        $secret = $this->google2fa->generateSecretKey();
        $plaintextBackup = 'ABCDE12345';
        $user = User::factory()->create([
            'google2fa_secret' => encrypt($secret),
            'google2fa_ts'     => (int) floor(now()->timestamp / 30),
            'mfa_backup_codes' => [\Illuminate\Support\Facades\Hash::make($plaintextBackup)],
        ]);

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => $plaintextBackup])
            ->assertRedirect();

        // Consumed: a second use fails.
        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => $plaintextBackup])
            ->assertSessionHasErrors('code');
    }
}
