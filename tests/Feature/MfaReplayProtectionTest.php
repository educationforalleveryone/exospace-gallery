<?php

declare(strict_types=1);

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

        $this->actingAs($user)->post('/mfa/verify', ['code' => $code])->assertRedirect();
        $stampedCounter = (int) $user->refresh()->google2fa_ts;
        $this->assertGreaterThan(0, $stampedCounter);

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => $code])
            ->assertSessionHasErrors('code');

        // Baseline unchanged — a failed attempt must never move it.
        $this->assertSame($stampedCounter, (int) $user->refresh()->google2fa_ts);
    }

    public function test_next_window_code_still_accepted_after_replay_protection(): void
    {
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
