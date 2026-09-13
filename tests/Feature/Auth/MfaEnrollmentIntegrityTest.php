<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

class MfaEnrollmentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->google2fa = new Google2FA;
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email_verified_at' => now(),
        ], $attributes));
    }

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

    public function test_enable_is_rejected_when_mfa_is_already_enabled(): void
    {
        $user = $this->enabledUser();
        $activeSecret = decrypt($user->google2fa_secret);
        $originalCodes = $user->mfa_backup_codes;
        $originalTs = $user->google2fa_ts;
        $originalEnabledAt = $user->mfa_enabled_at->getTimestamp();

        // A stale enrollment from an abandoned tab still holds a valid code.
        $staleSecret = $this->google2fa->generateSecretKey();

        $this->actingAs($user)
            ->withSession(['mfa_pending_secret' => $staleSecret])
            ->post('/mfa/setup', ['code' => $this->currentOtp($staleSecret)])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertSame($activeSecret, decrypt($user->google2fa_secret));
        $this->assertSame($originalCodes, $user->mfa_backup_codes);
        $this->assertSame($originalTs, (int) $user->google2fa_ts);
        $this->assertSame($originalEnabledAt, $user->mfa_enabled_at->getTimestamp());

        $this->assertDatabaseMissing('admin_audit_logs', [
            'action' => 'mfa.enabled',
            'actor_id' => $user->id,
        ]);
    }

    public function test_enable_without_a_pending_setup_session_cannot_enable_mfa(): void
    {
        $user = $this->user();
        $secret = $this->google2fa->generateSecretKey();

        $this->actingAs($user)
            ->post('/mfa/setup', ['code' => $this->currentOtp($secret)])
            ->assertRedirect(route('mfa.setup'))
            ->assertSessionHas('error');

        $user->refresh();
        $this->assertNull($user->google2fa_secret);
        $this->assertNull($user->mfa_enabled_at);
        $this->assertNull($user->mfa_backup_codes);
    }

    public function test_verify_post_fails_cleanly_for_accounts_without_mfa(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => '123456'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status');

        $this->assertNull(session('mfa_verified'));
    }

    public function test_setup_failure_redirects_to_settings_not_an_mfa_gated_route(): void
    {
        $google2fa = \Mockery::mock(Google2FA::class);
        $google2fa->shouldReceive('generateSecretKey')
            ->andThrow(new \RuntimeException('QR backend unavailable'));
        $this->instance(Google2FA::class, $google2fa);

        // Super admins without MFA are the loop-risk persona: admin.dashboard
        // would bounce straight back to mfa.setup.
        $superAdmin = $this->user(['is_super_admin' => true]);

        $this->actingAs($superAdmin)
            ->get('/mfa/setup')
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('error');

        // Following the redirect must not bounce back to setup.
        $this->actingAs($superAdmin)
            ->get(route('profile.edit'))
            ->assertOk();
    }

    public function test_only_the_latest_pending_secret_can_complete_enrollment(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/mfa/setup');
        $staleSecret = session('mfa_pending_secret');

        $this->actingAs($user)->get('/mfa/setup');
        $freshSecret = session('mfa_pending_secret');

        $this->assertNotSame($staleSecret, $freshSecret);

        // The abandoned tab's code no longer matches the pending secret.
        $this->actingAs($user)
            ->post('/mfa/setup', ['code' => $this->currentOtp($staleSecret)])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->refresh()->google2fa_secret);

        $this->actingAs($user)
            ->post('/mfa/setup', ['code' => $this->currentOtp($freshSecret)])
            ->assertRedirect(route('mfa.backup-codes'));

        $user->refresh();
        $this->assertSame($freshSecret, decrypt($user->google2fa_secret));
    }

    public function test_each_setup_visit_generates_a_fresh_base32_secret(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/mfa/setup');
        $first = session('mfa_pending_secret');

        $this->actingAs($user)->get('/mfa/setup');
        $second = session('mfa_pending_secret');

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $second);
    }

    public function test_enable_endpoint_is_rate_limited(): void
    {
        $user = $this->enabledUser();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post('/mfa/setup', ['code' => '000000']);
        }

        $this->actingAs($user)
            ->post('/mfa/setup', ['code' => '000000'])
            ->assertTooManyRequests();
    }

    public function test_mfa_routes_require_authentication(): void
    {
        foreach (['/mfa/setup', '/mfa/verify', '/mfa/backup-codes'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }

        foreach (['/mfa/setup', '/mfa/verify', '/mfa/disable'] as $path) {
            $this->post($path, [])->assertRedirect(route('login'));
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_enable_audit_payload_carries_no_secret_material(): void
    {
        $user = $this->user();
        $secret = $this->google2fa->generateSecretKey();

        $this->actingAs($user)
            ->withSession(['mfa_pending_secret' => $secret])
            ->post('/mfa/setup', ['code' => $this->currentOtp($secret)])
            ->assertRedirect(route('mfa.backup-codes'));

        $row = AdminAuditLog::query()
            ->where('action', 'mfa.enabled')
            ->where('actor_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $blob = json_encode([$row->payload, $row->toArray()]);
        $this->assertStringNotContainsString($secret, (string) $blob);
        $this->assertStringNotContainsString('$2y$', (string) $blob);
    }
}
