<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

class MfaRecoveryCodeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->google2fa = new Google2FA;
    }

    // ── Helpers (MfaLifecycleTest conventions) ───────────────────────────

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

    private function remainingCodes(User $user): int
    {
        $raw = DB::table('users')->where('id', $user->id)->value('mfa_backup_codes');

        return collect(json_decode((string) $raw, true) ?? [])->filter(fn ($c) => $c !== null)->count();
    }

    public function test_generated_codes_have_the_intended_format_and_are_unique_within_the_set(): void
    {
        $user = $this->user();
        $secret = $this->google2fa->generateSecretKey();

        $this->actingAs($user)
            ->withSession(['mfa_pending_secret' => $secret])
            ->post('/mfa/setup', ['code' => $this->currentOtp($secret)])
            ->assertRedirect(route('mfa.backup-codes'));

        $codes = session('backup_codes');
        $this->assertIsArray($codes);
        $this->assertCount(10, $codes);
        $this->assertSame($codes, array_values(array_unique($codes)), 'Duplicate code inside one set.');

        foreach ($codes as $code) {
            // CSPRNG output over [A-Za-z0-9], uppercased, dash-formatted.
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', $code);
        }
    }

    public function test_generated_codes_belong_to_the_enrolling_user_only(): void
    {
        $user = $this->user();
        $other = $this->user();
        $secret = $this->google2fa->generateSecretKey();

        $this->actingAs($user)
            ->withSession(['mfa_pending_secret' => $secret])
            ->post('/mfa/setup', ['code' => $this->currentOtp($secret)]);

        $user->refresh();
        $other->refresh();

        $this->assertCount(10, $user->mfa_backup_codes);
        $this->assertNull($other->mfa_backup_codes);
        $this->assertNull($other->google2fa_secret);
    }

    public function test_failed_10char_attempt_does_not_consume_any_code(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->from('/mfa/verify')
            ->post('/mfa/verify', ['code' => 'ZZZZZ99999'])
            ->assertSessionHasErrors('code');

        $this->assertSame(2, $this->remainingCodes($user), 'A failed attempt burned a valid code.');
    }

    public function test_consumption_re_reads_the_row_inside_the_transaction_not_the_stale_model(): void
    {
        $user = $this->enabledUser();

        $user->fresh()->forceFill([
            'mfa_backup_codes' => [Hash::make('CCCCC33333')],
        ])->save();

        // The stale model still carries hashes for A/B — both must fail now.
        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'AAAAA-11111'])
            ->assertSessionHasErrors('code');

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'BBBBB22222'])
            ->assertSessionHasErrors('code');

        // …and the fresh code succeeds.
        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'CCCCC-33333'])
            ->assertRedirect();

        $this->assertTrue(session('mfa_verified'));
        $this->assertSame(0, $this->remainingCodes($user));
    }

    public function test_double_submit_of_the_same_code_authenticates_only_once(): void
    {
        $user = $this->enabledUser();

        $staleSnapshot = User::find($user->id); // separate instance, same moment

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'AAAAA-11111'])
            ->assertRedirect();

        $this->actingAs($staleSnapshot) // pre-consumption snapshot of the codes
            ->post('/mfa/verify', ['code' => 'AAAAA-11111'])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, $this->remainingCodes($user));
    }

    public function test_consumption_audit_is_truthful_and_carries_no_code_material(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'BBBBB22222'])
            ->assertRedirect();

        $row = AdminAuditLog::query()
            ->where('action', 'mfa.backup_code_used')
            ->where('actor_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $payload = $row->payload;

        $this->assertSame(1, $payload['remaining_codes']);
        $this->assertIsInt($payload['code_index']);
        $this->assertStringNotContainsString('BBBBB22222', json_encode($payload));
    }

    public function test_concurrent_different_codes_cannot_resurrect_a_consumed_code(): void
    {
        $user = $this->enabledUser();

        $staleSnapshot = User::find($user->id); // separate instance, same moment

        // Request A consumes index 0…
        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'AAAAA-11111'])
            ->assertRedirect();

        // …request B consumes index 1 from ITS stale snapshot.
        $this->actingAs($staleSnapshot)
            ->post('/mfa/verify', ['code' => 'BBBBB22222'])
            ->assertRedirect();

        $this->assertSame(0, $this->remainingCodes($user));

        // Both consumed codes stay dead afterwards.
        $this->actingAs($user)->post('/mfa/verify', ['code' => 'AAAAA-11111'])
            ->assertSessionHasErrors('code');
        $this->actingAs($user)->post('/mfa/verify', ['code' => 'BBBBB22222'])
            ->assertSessionHasErrors('code');
    }

    // ── Regeneration (disable → re-enable model) ─────────────────────────

    public function test_re_enabling_replaces_the_set_and_old_codes_die(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)
            ->post('/mfa/disable', ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));

        $freshSecret = $this->google2fa->generateSecretKey();
        $this->actingAs($user->refresh())
            ->withSession(['mfa_pending_secret' => $freshSecret])
            ->post('/mfa/setup', ['code' => $this->currentOtp($freshSecret)])
            ->assertRedirect(route('mfa.backup-codes'));

        $newCodes = session('backup_codes');
        $this->assertCount(10, $newCodes);

        // Old codes are gone — not merely shadowed.
        $this->actingAs($user->refresh())
            ->post('/mfa/verify', ['code' => 'AAAAA-11111'])
            ->assertSessionHasErrors('code');
        $this->actingAs($user->refresh())
            ->post('/mfa/verify', ['code' => 'BBBBB22222'])
            ->assertSessionHasErrors('code');

        $plaintext = $newCodes[0];
        $this->actingAs($user->refresh())
            ->post('/mfa/verify', ['code' => $plaintext])
            ->assertRedirect();

        $this->assertTrue(session('mfa_verified'));
    }

    public function test_final_code_can_be_consumed_and_the_set_becomes_exhausted(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)->post('/mfa/verify', ['code' => 'AAAAA-11111'])->assertRedirect();
        $this->assertSame(1, $this->remainingCodes($user));

        $this->actingAs($user)->post('/mfa/verify', ['code' => 'BBBBB22222'])->assertRedirect();
        $this->assertSame(0, $this->remainingCodes($user));
    }

    public function test_exhausted_set_gets_guidance_while_totp_failure_stays_generic(): void
    {
        $user = $this->enabledUser();

        // Exhaust the set.
        $this->actingAs($user)->post('/mfa/verify', ['code' => 'AAAAA-11111'])->assertRedirect();
        $this->actingAs($user)->post('/mfa/verify', ['code' => 'BBBBB22222'])->assertRedirect();

        // A 10-character attempt against the exhausted set explains itself.
        $this->actingAs($user)
            ->from('/mfa/verify')
            ->post('/mfa/verify', ['code' => 'AAAAA-11111'])
            ->assertSessionHasErrors('code');
        $this->assertStringContainsString(
            'All of your backup codes have been used',
            session('errors')->first('code')
        );

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertSame('Invalid code. Please try again.', session('errors')->first('code'));
    }

    public function test_backup_code_hashes_are_hidden_from_model_serialization(): void
    {
        $user = $this->enabledUser();

        $serialized = $user->fresh()->toArray();
        $this->assertArrayNotHasKey('mfa_backup_codes', $serialized);
        $this->assertStringNotContainsString('$2y$', $user->fresh()->toJson());

        // PHP attribute access is unaffected — the profile card reads it.
        $this->assertSame(2, $user->fresh()->mfa_backup_codes !== null ? count(array_filter($user->fresh()->mfa_backup_codes)) : 0);
    }

    public function test_another_users_code_is_rejected_and_consumes_nothing(): void
    {
        $alice = $this->enabledUser(['email' => 'alice@example.test']);
        $bob = $this->enabledUser([
            'email' => 'bob@example.test',
            'mfa_backup_codes' => [
                Hash::make('CCCCC33333'),
                Hash::make('DDDDD44444'),
            ],
        ]);

        // Bob tries Alice's code — it must neither verify nor burn anything.
        $this->actingAs($bob)
            ->post('/mfa/verify', ['code' => 'AAAAA-11111'])
            ->assertSessionHasErrors('code');

        $this->assertSame(2, $this->remainingCodes($alice));
        $this->assertSame(2, $this->remainingCodes($bob));
        $this->assertNull(session('mfa_verified'));
    }

    public function test_no_log_line_ever_contains_recovery_code_material(): void
    {
        $captured = [];
        Log::listen(function ($event) use (&$captured) {
            $captured[] = [$event->level, $event->message, (array) $event->context];
        });

        $user = $this->enabledUser();

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => 'AAAAA-11111'])
            ->assertRedirect();

        // Also log a failed attempt path.
        $this->actingAs($user)->post('/mfa/verify', ['code' => 'ZZZZZ99999']);

        $this->assertNotEmpty($captured, 'Expected some log activity to inspect.');
        foreach ($captured as [$level, $message, $context]) {
            $blob = json_encode([$message, $context]);
            $this->assertStringNotContainsString('AAAAA11111', (string) $blob);
            $this->assertStringNotContainsString('AAAAA-11111', (string) $blob);
            $this->assertStringNotContainsString('$2y$', (string) $blob);
        }
    }
}
