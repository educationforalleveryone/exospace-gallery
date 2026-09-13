<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use ReflectionProperty;
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

    // ── Intended URL (?redirect=) sanitization ─────────────────────────

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

        // the pricing page deep-links /login?redirect=billing/upgrade/pro
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

    // ── Authentication state behavior ──────────────────────────────────

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

    // ── Validation + error handling ─────────────────────────────────────

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

    // ── Banned users at login time ──────────────────────────────────────

    public function test_banned_users_cannot_log_in(): void
    {
        $user = User::factory()->banned('Terms violation')->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Your account has been banned. Reason: Terms violation',
            (string) session('errors')->first('email')
        );
    }

    public function test_banned_users_get_a_clean_error_without_reason_leakage(): void
    {
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

    public function test_checked_remember_me_restores_authentication_after_server_side_session_loss(): void
    {
        $user = User::factory()->create();
        $rememberCookie = Auth::guard('web')->getRecallerName();

        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);
        $this->assertAuthenticated();

        $recaller = $login->getCookie($rememberCookie, decrypt: false)?->getValue();
        $this->assertNotNull($recaller, 'A remember-me login must issue the recaller cookie.');

        $this->flushServerSideSession();

        $this->withUnencryptedCookie($rememberCookie, $recaller)
            ->get('/profile')
            ->assertOk();

        // assertAuthenticatedAs's second arg is the GUARD name, not a message.
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_unchecked_remember_me_issues_no_recaller_and_survives_no_session_loss(): void
    {
        $user = User::factory()->create();
        $rememberCookie = Auth::guard('web')->getRecallerName();

        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $this->assertAuthenticated();

        $login->assertCookieMissing($rememberCookie,
            'A login without remember-me must not issue the recaller cookie.');

        $this->flushServerSideSession();

        $this->get('/profile')->assertRedirect(route('login', absolute: false));
        $this->assertGuest();
    }

    // ── Lockout / rate limiting ─────────────────────────────────────────

    public function test_repeated_failures_lock_the_login_form_until_the_window_decays(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        RateLimiter::hit(Str::transliterate(Str::lower($user->email)).'|127.0.0.1');

        $locked = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $locked->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringContainsString(
            'Too many login attempts',
            (string) session('errors')->first('email')
        );

        // Even valid credentials stay locked until the limiter window decays.
        $this->travel(61)->seconds();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_a_successful_login_clears_the_failed_attempt_counter(): void
    {
        $user = User::factory()->create();
        $key = Str::transliterate(Str::lower($user->email)).'|127.0.0.1';

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

        $this->assertSame(2, RateLimiter::attempts($key));

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticated();

        $this->assertSame(
            0,
            RateLimiter::attempts($key),
            'A successful login must reset failed attempts for the credential.'
        );
    }

    public function test_a_locked_credential_does_not_prevent_a_different_credential_from_logging_in(): void
    {
        $lockedUser = User::factory()->create();
        $freshUser = User::factory()->create();
        $lockedKey = Str::transliterate(Str::lower($lockedUser->email)).'|127.0.0.1';
        $freshKey = Str::transliterate(Str::lower($freshUser->email)).'|127.0.0.1';

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', [
                'email' => $lockedUser->email,
                'password' => 'wrong-password',
            ]);
        }

        RateLimiter::hit($lockedKey);

        $this->post('/login', [
            'email' => $lockedUser->email,
            'password' => 'password',
        ]);
        $this->assertGuest();

        // The fresh credential's limiter bucket is untouched by the lockout.
        $this->assertSame(0, RateLimiter::attempts($freshKey));
        $this->assertFalse(RateLimiter::tooManyAttempts($freshKey, 5));

        $this->travel(61)->seconds();

        $this->post('/login', [
            'email' => $freshUser->email,
            'password' => 'password',
        ]);
        $this->assertAuthenticatedAs($freshUser);
    }

    // ── CSRF + credential handling ──────────────────────────────────────

    public function test_login_submission_is_not_exempt_from_csrf_validation(): void
    {
        // Feature tests bypass the token check, so assert the config instead:
        // no CSRF exclusion pattern may match the login POST route.
        $neverVerify = (new ReflectionProperty(VerifyCsrfToken::class, 'neverVerify'))
            ->getValue();

        foreach ((array) $neverVerify as $pattern) {
            $this->assertStringNotContainsString(
                'login',
                (string) $pattern,
                "CSRF exclusion '{$pattern}' must not cover the login route."
            );
        }
    }

    public function test_failed_login_attempts_never_write_the_password_to_logs(): void
    {
        $captured = [];
        Log::listen(function ($event) use (&$captured) {
            $captured[] = [$event->level, $event->message, $event->context];
        });

        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'super-secret-password',
        ]);

        $this->assertGuest();

        foreach ($captured as [$level, $message, $context]) {
            $this->assertStringNotContainsString('super-secret-password', (string) $message);
            $this->assertStringNotContainsString('super-secret-password', json_encode($context) ?: '');
        }
    }

    // ── Intended URL precedence ─────────────────────────────────────────

    public function test_malicious_redirect_param_cannot_evict_a_bounce_originated_intended_url(): void
    {
        $user = User::factory()->create();

        $this->get('/billing'); // guest bounce seeds url.intended with /billing

        $this->get('/login?redirect=//evil.example'); // rejected, must not overwrite

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();

        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/billing', $location);
        $this->assertStringNotContainsString('evil.example', $location);
    }

    private function flushServerSideSession(): void
    {
        $handler = $this->app['session.store']->getHandler();
        $storageProperty = new ReflectionProperty($handler, 'storage');
        $storageProperty->setValue($handler, []);

        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('session');
        $this->app['auth']->forgetGuards();
    }
}
