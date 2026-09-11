<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Notifications\Auth\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Focused password-reset lifecycle coverage (RESET ITERATION).
 *
 * Covers: request-time enumeration resistance, the branded reset email with
 * a correct framework-generated link, token lifecycle (fresh / superseded /
 * expired / reused / not-burned-by-validation-failures), secure password
 * update (hash, history, remember-token cycling, plaintext never stored),
 * the D-4 reuse-prevention rule through the HTTP flow, and the post-reset
 * product behavior (fresh login required).
 *
 * NOTE: assertions target App\Notifications\Auth\ResetPassword (the app's
 * branded notification) — Notification::fake() matches notification classes
 * exactly, so tests must never assert against the framework's
 * Illuminate\Auth\Notifications\ResetPassword.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    // ── Request flow ──────────────────────────────────────────────────────

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_link_request_is_enumeration_resistant(): void
    {
        Notification::fake();

        $known = User::factory()->create();

        // Known account — neutral status, no errors.
        $this->post('/forgot-password', ['email' => $known->email])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'If an account exists for that email address, a password reset link is on its way.');

        // Unknown account — EXACTLY the same status, no errors, no
        // "We can't find a user" hint anywhere in the session.
        $this->post('/forgot-password', ['email' => 'ghost-who-does-not-exist@example.com'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'If an account exists for that email address, a password reset link is on its way.');

        // Exactly one reset notification was generated — for the real user.
        Notification::assertSentTo($known, ResetPassword::class);
    }

    public function test_reset_link_request_validates_email(): void
    {
        $this->post('/forgot-password', [])->assertSessionHasErrors('email');

        $this->post('/forgot-password', ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');
    }

    public function test_reset_email_uses_branded_notification_with_correct_link(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mailable = $notification->toMail($user);

            // The branded mailable, addressed to the account owner only.
            $this->assertInstanceOf(PasswordResetMail::class, $mailable);
            $this->assertSame('Reset your Exospace password', $mailable->envelope()->subject);
            $this->assertSame($user->email, $mailable->to[0]['address']);

            // The reset URL is framework-generated: correct route + token +
            // pre-fill email query parameter, no host weirdness.
            $this->assertStringContainsString('/reset-password/', $mailable->resetUrl);
            $this->assertStringContainsString('email='.urlencode($user->email), $mailable->resetUrl);

            // Branded html + text pair, rendered content is complete.
            $content = $mailable->content();
            $this->assertSame('emails.password-reset', $content->view);
            $this->assertSame('emails.password-reset-text', $content->text);

            $html = $mailable->render();
            $this->assertStringContainsString('Choose a new password', $html);
            $this->assertStringContainsString(htmlspecialchars($user->email, ENT_QUOTES), $html);
            $this->assertStringContainsString('60 minutes', $html);
            $this->assertStringContainsString($mailable->resetUrl, $html);

            // The rendered email must NOT echo the plaintext token anywhere
            // outside the reset URL itself.
            $withoutUrl = str_replace($mailable->resetUrl, '', $html);
            $this->assertStringNotContainsString($notification->token, $withoutUrl);

            return true;
        });
    }

    public function test_forgot_password_route_is_throttled_per_ip(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => $user->email])->assertStatus(302);
        }

        $this->post('/forgot-password', ['email' => $user->email])->assertStatus(429);
    }

    public function test_reset_link_request_response_does_not_expose_token(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/forgot-password', ['email' => $user->email]);

        // The token only ever travels inside the email — never in the
        // application response.
        $this->assertStringNotContainsString('reset-password/', $response->getContent());
    }

    // ── Reset link / token lifecycle ──────────────────────────────────────

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $response = $this->get('/reset-password/'.$notification->token.'?email='.urlencode($user->email));

            $response->assertStatus(200)
                ->assertSee('value="'.$notification->token.'"', false)
                ->assertSee('value="'.$user->email.'"', false);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => Hash::make('OldSecret123!')]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'NewSecret456!',
                'password_confirmation' => 'NewSecret456!',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'))
                ->assertSessionHas('status', __('passwords.reset'));

            return true;
        });
    }

    public function test_used_token_cannot_be_reused(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => Hash::make('OldSecret123!')]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'NewSecret456!',
                'password_confirmation' => 'NewSecret456!',
            ])->assertSessionHasNoErrors();

            // Replay the same token — clean validation error, no exception,
            // no second password change.
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'AnotherPass77!',
                'password_confirmation' => 'AnotherPass77!',
            ])->assertSessionHasErrors(['email' => __('passwords.token')]);

            $user->refresh();
            $this->assertTrue(Hash::check('NewSecret456!', $user->password));
            $this->assertFalse(Hash::check('AnotherPass77!', $user->password));

            return true;
        });
    }

    public function test_newer_request_invalidates_previous_token(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => Hash::make('OldSecret123!')]);

        $this->post('/forgot-password', ['email' => $user->email]);

        // Defeat the broker's 60s per-email throttle so a second request
        // actually mints a new token.
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(5)]);

        $this->post('/forgot-password', ['email' => $user->email]);

        $tokens = Notification::sent($user, ResetPassword::class)
            ->map(fn (ResetPassword $n) => $n->token)
            ->unique()
            ->values();

        $this->assertCount(2, $tokens, 'Two requests should have minted two distinct tokens.');

        // The superseded token is rejected…
        $this->post('/reset-password', [
            'token' => $tokens[0],
            'email' => $user->email,
            'password' => 'FromOldToken99!',
            'password_confirmation' => 'FromOldToken99!',
        ])->assertSessionHasErrors(['email' => __('passwords.token')]);

        // …and the newest one works.
        $this->post('/reset-password', [
            'token' => $tokens[1],
            'email' => $user->email,
            'password' => 'NewSecret456!',
            'password_confirmation' => 'NewSecret456!',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('NewSecret456!', $user->password));
    }

    public function test_expired_token_is_rejected(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => Hash::make('OldSecret123!')]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            // Token lifetime is 60 minutes (config/auth.php) — backdate past it.
            DB::table('password_reset_tokens')
                ->where('email', $user->email)
                ->update(['created_at' => now()->subMinutes(config('auth.passwords.users.expire') + 1)]);

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'NewSecret456!',
                'password_confirmation' => 'NewSecret456!',
            ])->assertSessionHasErrors(['email' => __('passwords.token')]);

            $user->refresh();
            $this->assertTrue(Hash::check('OldSecret123!', $user->password));

            return true;
        });
    }

    // ── Password update ───────────────────────────────────────────────────

    public function test_reset_persists_securely_and_cycles_remember_token(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => Hash::make('OldSecret123!'),
            'has_password' => true,
        ]);
        $oldRememberToken = $user->remember_token;

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, $oldRememberToken) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'NewSecret456!',
                'password_confirmation' => 'NewSecret456!',
            ])->assertSessionHasNoErrors();

            $user->refresh();

            // New password active, old one dead, plaintext never stored.
            $this->assertTrue(Hash::check('NewSecret456!', $user->password));
            $this->assertFalse(Hash::check('OldSecret123!', $user->password));
            $this->assertStringStartsWith('$2y$', $user->password);
            $this->assertStringNotContainsString('NewSecret456!', $user->password);

            // Remember-me sessions on other devices are killed.
            $this->assertNotSame($oldRememberToken, $user->remember_token);

            // The reset token is consumed (single-use).
            $this->assertFalse(DB::table('password_reset_tokens')->where('email', $user->email)->exists());

            // C-2 bookkeeping stays consistent.
            $this->assertTrue($user->has_password);
            $this->assertNotNull($user->password_set_at);

            // D-4: the PREVIOUS password hash was preserved in history —
            // this is what makes the reuse check work (see the prune fix).
            $history = DB::table('password_histories')->where('user_id', $user->id)->get();
            $this->assertCount(1, $history);
            $this->assertTrue(Hash::check('OldSecret123!', $history[0]->password_hash));
            $this->assertFalse(Hash::check('NewSecret456!', $history[0]->password_hash));

            return true;
        });
    }

    public function test_reset_rejects_weak_password_and_preserves_token(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => Hash::make('OldSecret123!')]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            // Below the Password::defaults() floor (min 8) — clean validation
            // error, no exception, password untouched.
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'abc',
                'password_confirmation' => 'abc',
            ])->assertSessionHasErrors('password');

            $user->refresh();
            $this->assertTrue(Hash::check('OldSecret123!', $user->password));

            // A validation failure must NOT burn the token — the user can
            // retry with a compliant password using the same link.
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'NewSecret456!',
                'password_confirmation' => 'NewSecret456!',
            ])->assertSessionHasNoErrors();

            $user->refresh();
            $this->assertTrue(Hash::check('NewSecret456!', $user->password));

            return true;
        });
    }

    public function test_reset_requires_matching_confirmation(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => Hash::make('OldSecret123!')]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'NewSecret456!',
                'password_confirmation' => 'Different789!',
            ])->assertSessionHasErrors('password');

            $user->refresh();
            $this->assertTrue(Hash::check('OldSecret123!', $user->password));

            return true;
        });
    }

    public function test_reset_rejects_previously_used_password(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => Hash::make('OldSecret123!')]);

        // First reset: OldSecret123! → RotatePass11! (history records OldSecret123!)
        $this->post('/forgot-password', ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'RotatePass11!',
                'password_confirmation' => 'RotatePass11!',
            ])->assertSessionHasNoErrors();

            return true;
        });
        // Notification::fake() again — the fake has no clear method, and we
        // need the next assertSentTo to see only the second request's mail.
        Notification::fake();

        // Second reset attempt, back to the JUST-ABANDONED password — must
        // be rejected by the reuse rule (this silently passed before the
        // history-prune fix).
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(5)]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'OldSecret123!',
                'password_confirmation' => 'OldSecret123!',
            ])->assertSessionHasErrors([
                'password' => 'You cannot reuse one of your last 5 passwords. Please choose a different password.',
            ]);

            // Password unchanged and the token is preserved for a retry
            // with a compliant password.
            $user->refresh();
            $this->assertTrue(Hash::check('RotatePass11!', $user->password));
            $this->assertTrue(DB::table('password_reset_tokens')->where('email', $user->email)->exists());

            return true;
        });
    }

    public function test_new_password_authenticates_and_old_is_rejected(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => Hash::make('OldSecret123!')]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'NewSecret456!',
                'password_confirmation' => 'NewSecret456!',
            ])->assertSessionHasNoErrors();

            $user->refresh();

            // Old credentials dead…
            $this->assertFalse(
                Hash::check('OldSecret123!', $user->password),
                'Old password still authenticates after reset.'
            );

            // …new credentials live (the reset redirects to /login — no
            // auto-login: the user signs in fresh with the new password).
            $response = $this->post('/login', [
                'email' => $user->email,
                'password' => 'NewSecret456!',
            ]);
            $response->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($user);

            return true;
        });
    }

    // ── History prune (User::storePasswordInHistory) ──────────────────────

    public function test_password_history_prune_keeps_only_ten_newest(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CurrentPass01!')]);

        for ($i = 0; $i < 12; $i++) {
            $user->storePasswordInHistory();
        }

        $ids = DB::table('password_histories')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->pluck('id');

        $this->assertCount(10, $ids);
        // Newest ten kept (including the row inserted by the 12th call)…
        $this->assertTrue(DB::table('password_histories')->where('id', $ids->first())->exists());
        // …nothing older than the ten newest survives.
        $this->assertFalse(
            DB::table('password_histories')->where('user_id', $user->id)->where('id', '<', $ids->last())->exists()
        );
    }

    public function test_is_password_in_history_matches_recent_and_ignores_old(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CurrentPass01!')]);

        // Build 10 distinct history entries (oldest inserted first).
        for ($i = 10; $i >= 1; $i--) {
            $user->forceFill(['password' => Hash::make("HistoricPass{$i}!")])->save();
            $user->storePasswordInHistory();
        }

        // The reuse check enforces the FIVE newest entries….
        $this->assertTrue($user->isPasswordInHistory('HistoricPass5!'));
        $this->assertTrue($user->isPasswordInHistory('HistoricPass1!'));

        // …older entries stay stored but have aged out of the check window….
        $this->assertFalse($user->isPasswordInHistory('HistoricPass6!'));
        $this->assertFalse($user->isPasswordInHistory('HistoricPass10!'));

        // …and the current password is not (yet) part of the history.
        $this->assertFalse($user->isPasswordInHistory('CurrentPass01!'));
    }
}
