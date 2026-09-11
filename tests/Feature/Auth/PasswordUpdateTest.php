<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordChangedNoticeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    // ── Successful change ────────────────────────────────────────────────

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_old_password_no_longer_authenticates_and_new_password_does(): void
    {
        $user = User::factory()->create(); // factory default password: "password"

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'brand-new-pw-456',
                'password_confirmation' => 'brand-new-pw-456',
            ])->assertSessionHasNoErrors();

        // The authenticated client must sign out before the login endpoint
        // (guest middleware) is reachable again.
        $this->post('/logout');

        // Old password is rejected at the login endpoint…
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrorsIn('default', 'email');

        // …and the new password logs in.
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'brand-new-pw-456',
        ])->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_successful_change_purges_pending_reset_link_notifies_owner_and_audits(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        // A reset link was requested BEFORE the credential change.
        $resetToken = DB::table('password_reset_tokens')->insertGetId([
            'email' => $user->email,
            'token' => hash_hmac('sha256', 'pending-token', config('app.key')),
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'brand-new-pw-456',
                'password_confirmation' => 'brand-new-pw-456',
            ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'password-updated');

        // The pending reset link died with the credential it belonged to.
        $this->assertDatabaseMissing('password_reset_tokens', ['id' => $resetToken]);

        // The security notice reached the account's own inbox (queued).
        Mail::assertQueued(PasswordChangedNoticeMail::class, 1);
        Mail::assertQueued(PasswordChangedNoticeMail::class, function (PasswordChangedNoticeMail $mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        // The change is audited like the other self-service security actions.
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'password_changed',
            'actor_id' => $user->id,
            'target_type' => User::class,
            'target_id' => $user->id,
        ]);
    }

    public function test_session_id_is_regenerated_after_a_successful_change(): void
    {
        $user = User::factory()->create();

        $before = session()->getId();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'brand-new-pw-456',
                'password_confirmation' => 'brand-new-pw-456',
            ])->assertSessionHasNoErrors();

        $this->assertNotSame($before, session()->getId());

        // The user stays authenticated on the regenerated session.
        $this->assertAuthenticatedAs($user);
        $this->get('/profile')->assertOk();
    }

    public function test_remember_token_is_cycled_so_remembered_devices_must_reauthenticate(): void
    {
        $user = User::factory()->create();
        $oldToken = $user->remember_token;

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'brand-new-pw-456',
                'password_confirmation' => 'brand-new-pw-456',
            ])->assertSessionHasNoErrors();

        $this->assertNotSame($oldToken, $user->refresh()->remember_token);
    }

    public function test_password_set_at_is_refreshed_by_the_change(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['password_set_at' => now()->subDays(30)])->save();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'brand-new-pw-456',
                'password_confirmation' => 'brand-new-pw-456',
            ])->assertSessionHasNoErrors();

        $this->assertTrue($user->refresh()->password_set_at->gt(now()->subDay()));
    }

    // ── Rejection ────────────────────────────────────────────────────────

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');
    }

    public function test_current_password_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'different-password',
            ])->assertSessionHasErrorsIn('updatePassword', 'password')
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_new_password_must_satisfy_the_established_policy(): void
    {
        $user = User::factory()->create();

        // The app's established policy: Password::defaults() (min 8 chars).
        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])->assertSessionHasErrorsIn('updatePassword', 'password')
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_unusual_but_valid_password_characters_are_accepted(): void
    {
        $user = User::factory()->create();
        $exotic = 'äCorrect Horse 🐴 battery-Staple 安全 42!';

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => $exotic,
                'password_confirmation' => $exotic,
            ])->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check($exotic, $user->refresh()->password));
    }

    public function test_password_reuse_is_rejected_and_the_message_reaches_the_form(): void
    {
        $user = User::factory()->create();

        // First change: "password" → "brand-new-pw-456". The old hash is
        // stored in password_histories on the way.
        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'brand-new-pw-456',
                'password_confirmation' => 'brand-new-pw-456',
            ])->assertSessionHasNoErrors();

        // Second change: attempting to reuse the FIRST password is rejected,
        // and the rejection lands in the 'updatePassword' bag the profile
        // form actually renders — historically it landed in the default bag
        // and the user got no feedback at all (ITERATION-8 regression test).
        $this->actingAs($user->refresh())
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'brand-new-pw-456',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertSessionHasErrorsIn('updatePassword', 'password')
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('brand-new-pw-456', $user->refresh()->password));
    }

    public function test_unauthenticated_user_cannot_access_the_password_change_operation(): void
    {
        $user = User::factory()->create();

        $this->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'attacker-pw-1',
                'password_confirmation' => 'attacker-pw-1',
            ])->assertRedirect(route('login'));

        // Nothing happened: no credential change, no audit, no notice.
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
        $this->assertDatabaseCount('admin_audit_logs', 0);
        Mail::fake();
        Mail::assertNothingQueued();
    }

    // ── Security ─────────────────────────────────────────────────────────

    public function test_password_is_hashed_and_plaintext_is_never_persisted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'brand-new-pw-456',
                'password_confirmation' => 'brand-new-pw-456',
            ])->assertSessionHasNoErrors();

        $fresh = $user->refresh();
        $this->assertNotSame('brand-new-pw-456', $fresh->password);
        $this->assertStringStartsWith('$2y$', $fresh->password);
        $this->assertTrue(Hash::check('brand-new-pw-456', $fresh->password));

        // password_histories stores HASHES only — never the plaintext.
        $this->assertDatabaseMissing('password_histories', ['password_hash' => 'brand-new-pw-456']);
        $this->assertDatabaseMissing('password_histories', ['password_hash' => 'password']);
    }

    public function test_audit_entry_and_security_notice_never_expose_credentials(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'brand-new-pw-456',
                'password_confirmation' => 'brand-new-pw-456',
            ])->assertSessionHasNoErrors();

        $audit = DB::table('admin_audit_logs')->where('action', 'password_changed')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('brand-new-pw-456', (string) $audit->payload);
        $this->assertStringNotContainsString('$2y$', (string) $audit->payload);

        // The queued notice body carries no credential material either.
        Mail::assertQueued(PasswordChangedNoticeMail::class, function (PasswordChangedNoticeMail $mail) {
            $html = $mail->render();

            return ! str_contains($html, 'brand-new-pw-456')
                && ! str_contains($html, '$2y$');
        });
    }

    public function test_change_does_not_disturb_email_or_mfa_state(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'email_verified_at' => now(),
        ]);
        $user->forceFill([
            'google2fa_secret' => encrypt('GA7W2KUTZQRQW2KUTZQRQW2KUTZQRQW2'),
            'mfa_enabled_at' => now()->subDays(2),
        ])->save();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'brand-new-pw-456',
                'password_confirmation' => 'brand-new-pw-456',
            ])->assertSessionHasNoErrors();

        $fresh = $user->refresh();
        $this->assertSame('owner@example.test', $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertNotNull($fresh->google2fa_secret);
        $this->assertNotNull($fresh->mfa_enabled_at);
    }

    // ── UX surface ───────────────────────────────────────────────────────

    public function test_profile_page_renders_the_password_form_with_submit_guard(): void
    {
        $user = User::factory()->create();

        $page = $this->actingAs($user)->get('/profile');

        $page->assertOk()
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('autocomplete="current-password"', false)
            // The app-wide double-submission guard (ITERATION-8).
            ->assertSee('data-busy', false)
            ->assertSee(route('password.update'), false);
    }

    public function test_oauth_only_account_sees_guidance_instead_of_a_dead_form(): void
    {
        // OAuth-only users hold an unusable random placeholder hash — the
        // current-password check can never pass for them.
        $user = User::factory()->create([
            'password' => Hash::make(\Illuminate\Support\Str::random(32)),
            'has_password' => false,
        ]);

        $page = $this->actingAs($user)->get('/profile');

        $page->assertOk()
            // Guidance toward the app's real set-a-password path…
            ->assertSee('Forgot password')
            // …and no form whose submission could never succeed.
            ->assertDontSee('name="current_password"', false);

        // The endpoint itself still refuses them (defense in depth).
        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'whatever-they-type',
                'password' => 'brand-new-pw-456',
                'password_confirmation' => 'brand-new-pw-456',
            ])->assertSessionHasErrorsIn('updatePassword', 'current_password');
    }

    // ── Throttling ───────────────────────────────────────────────────────

    public function test_password_update_is_rate_limited_in_its_own_bucket(): void
    {
        $user = User::factory()->create();

        // Five failed attempts consume the password-update bucket…
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)
                ->from('/profile')
                ->put('/password', [
                    'current_password' => 'wrong-guess-'.$i,
                    'password' => 'brand-new-pw-456',
                    'password_confirmation' => 'brand-new-pw-456',
                ])->assertSessionHasErrorsIn('updatePassword', 'current_password');
        }

        // …the sixth attempt is throttled.
        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-guess-5',
                'password' => 'brand-new-pw-456',
                'password_confirmation' => 'brand-new-pw-456',
            ])->assertStatus(429);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));

        // ITERATION-8: the bucket is named — the shared login bucket is
        // untouched by the password-update attempts above.
        $this->post('/logout');
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
    }
}
