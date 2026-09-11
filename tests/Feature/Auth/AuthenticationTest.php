<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    // ── LOGIN-ITERATION: intended-URL (?redirect=) sanitization ─────────

    public function test_relative_redirect_query_param_is_honored_after_login(): void
    {
        $user = User::factory()->create();

        $this->get('/login?redirect=/billing');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertStringContainsString(
            '/billing',
            (string) $response->headers->get('Location')
        );
    }

    public function test_bare_relative_redirect_query_param_is_prefixed_with_slash(): void
    {
        $user = User::factory()->create();

        // CONV-6: the pricing page deep-links /login?redirect=billing/upgrade/pro
        $this->get('/login?redirect=billing/upgrade/pro');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertStringContainsString(
            '/billing/upgrade/pro',
            (string) $response->headers->get('Location')
        );
    }

    public function test_absolute_external_redirect_query_param_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->get('/login?redirect=https://evil.example');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_protocol_relative_redirect_query_param_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->get('/login?redirect=//evil.example');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_backslash_prefixed_redirect_query_param_is_rejected(): void
    {
        $user = User::factory()->create();

        // WHATWG URL parsing treats "\" as "/", so "/\evil.example" is a
        // scheme-relative form in disguise — it must never be stored as
        // the intended URL. (LOGIN-ITERATION hardening.)
        $this->get('/login?redirect=/\\evil.example');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_redirect_query_param_with_control_characters_is_rejected(): void
    {
        $user = User::factory()->create();

        // Header-injection-shaped values must never reach url.intended.
        $this->get('/login?redirect='.urlencode("/billing\r\nSet-Cookie: x=1"));

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_intended_url_from_auth_bounce_is_restored_after_login(): void
    {
        $user = User::factory()->create();

        // Guest hits a protected route → bounced to login with
        // url.intended set by the framework's redirect()->guest().
        $this->get('/dashboard');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertStringContainsString(
            '/dashboard',
            (string) $response->headers->get('Location')
        );
    }

    // ── LOGIN-ITERATION: authentication state behavior ──────────────────

    public function test_already_authenticated_users_are_redirected_away_from_login(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_authentication_state_persists_across_subsequent_requests(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();

        // A fresh request on the same session must still resolve the user
        // (refresh/navigation after login) — the guest middleware proves
        // it by bouncing the now-authenticated user away from /login.
        $this->get('/login')->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_session_id_is_regenerated_on_successful_login(): void
    {
        $user = User::factory()->create();

        // Pre-login request establishes the (attacker-known) session.
        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertNotSame(
            $before,
            session()->getId(),
            'Session fixation guard: session ID must change on login.'
        );
    }

    // ── LOGIN-ITERATION: validation + error handling ────────────────────

    public function test_empty_credentials_are_rejected_with_validation_errors(): void
    {
        $response = $this->post('/login', [
            'email' => '',
            'password' => '',
        ]);

        $response->assertSessionHasErrors(['email', 'password']);
        $this->assertGuest();
    }

    public function test_malformed_email_is_rejected_with_validation_error(): void
    {
        $response = $this->post('/login', [
            'email' => 'not-an-email',
            'password' => 'whatever1',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_missing_password_is_rejected_with_validation_error(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => '',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_unknown_email_and_wrong_password_produce_identical_errors(): void
    {
        User::factory()->create();

        // Wrong password for an existing account.
        $this->post('/login', [
            'email' => 'existing-user@example.test',
            'password' => 'wrong-password',
        ]);
        $wrongPassword = (string) session('errors')->first('email');

        // Unknown (nonexistent) account.
        $this->post('/login', [
            'email' => 'nobody@example.test',
            'password' => 'also-wrong',
        ]);
        $unknownEmail = (string) session('errors')->first('email');

        $this->assertNotSame('', $wrongPassword);
        $this->assertSame($wrongPassword, $unknownEmail,
            'Login errors must not reveal whether an account exists.');
    }

    // ── LOGIN-ITERATION: banned users at login time ─────────────────────

    public function test_banned_users_cannot_log_in(): void
    {
        $user = User::factory()->banned('Terms violation')->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();

        // The ban message must surface on the login error bag (same
        // sanitized format the CheckBanned middleware uses).
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Your account has been banned. Reason: Terms violation',
            (string) session('errors')->first('email')
        );
    }

    public function test_banned_users_get_a_clean_error_without_reason_leakage(): void
    {
        // Ban reason entered as markup — must be sanitized before display
        // (same contract as CheckBanned's SEC-16 fix).
        $user = User::factory()->banned('<script>alert(1)</script> spam')->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $this->assertStringNotContainsString(
            '<script>',
            (string) session('errors')->first('email')
        );
    }

    public function test_unbanned_users_can_still_log_in_after_ban_flow_changes(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
