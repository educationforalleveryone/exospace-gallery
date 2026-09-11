<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * LOGOUT-ITERATION — focused logout / authenticated-session-lifecycle tests.
 *
 * Covers the production-readiness mission for POST /logout:
 *   - successful logout terminates the session deterministically;
 *   - the session identifier is regenerated (no reuse after logout);
 *   - session payload (intended URL, sudo-mode style flags) is discarded;
 *   - the CSRF token is rotated;
 *   - remember-me cookies are deleted and the remember token is cycled;
 *   - protected routes refuse the user immediately after logout;
 *   - repeated / stale logout submissions fail gracefully (no exception);
 *   - authenticated HTML + redirects carry `Cache-Control: no-store`
 *     (back-button / cached-page fix) while guest responses are untouched;
 *   - the branded 419 page renders with a way forward.
 *
 * Environment note: CSRF validation is auto-skipped for in-process feature
 * tests (APP_ENV=testing), so the REAL 419 path for stale CSRF tokens is
 * verified over live HTTP in the iteration's E2E harness, not here.
 */
class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_terminates_the_session_and_redirects_home(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_logout_regenerates_the_session_identifier(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['_token' => 'before-logout-token'])
            ->get('/verify-email');

        $idBeforeLogout = session()->getId();

        $this->actingAs($user)->post('/logout');

        $this->assertNotSame(
            $idBeforeLogout,
            session()->getId(),
            'Logout must invalidate (regenerate the ID of) the session so a stolen pre-logout session ID is worthless afterwards.'
        );
    }

    public function test_logout_discards_all_session_data_beyond_authentication(): void
    {
        $user = User::factory()->create();

        // url.intended is what redirect()->intended() consumes; a sudo-mode
        // style flag proves that any sensitive session state is wiped by the
        // same invalidate() call, not just the login flag.
        $response = $this->actingAs($user)
            ->withSession([
                'url.intended' => '/billing',
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->post('/logout');

        $response->assertSessionMissing('url.intended');
        $response->assertSessionMissing('auth.password_confirmed_at');
    }

    public function test_logout_rotates_the_csrf_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/verify-email');
        $tokenBefore = csrf_token();

        $this->actingAs($user)->post('/logout');

        $this->assertNotSame(
            $tokenBefore,
            csrf_token(),
            'regenerateToken() must rotate the CSRF token so forms rendered before logout cannot be replayed into the fresh session.'
        );
    }

    public function test_protected_routes_require_authentication_after_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        // A small representative set of authenticated destinations —
        // dashboard entry point, profile, billing.
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/profile')->assertRedirect(route('login'));
        $this->get('/billing')->assertRedirect(route('login'));
    }

    public function test_repeated_logout_fails_gracefully(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        // Second submission (double click, stale page, replayed tab):
        // unauthenticated POST /logout must bounce to the login page —
        // never an exception, never an inconsistent state.
        $this->post('/logout')->assertRedirect(route('login'));
        $this->get('/')->assertOk();
    }

    public function test_logout_deletes_the_remember_me_cookie_and_cycles_the_remember_token(): void
    {
        // Resolve the recaller cookie name from the guard itself instead of
        // hardcoding its sha1 hash.
        $rememberCookie = Auth::guard('web')->getRecallerName();

        $user = User::factory()->create();

        // Real login flow with "remember me" so a remember token exists.
        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $login->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $tokenAfterLogin = $user->fresh()->remember_token;
        $this->assertNotNull($tokenAfterLogin, 'A remember-me login must persist a remember token.');
        $login->assertCookie($rememberCookie);

        // The feature-test client does not keep a cookie jar between
        // requests — send the remember cookie the way a real browser would
        // (raw/encrypted value, as it sits in the browser's cookie jar).
        $rawRememberCookie = $login->getCookie($rememberCookie, decrypt: false);
        $rememberCookieValue = $rawRememberCookie?->getValue();
        $this->assertNotNull($rememberCookieValue);

        $logout = $this->withCookie($rememberCookie, $rememberCookieValue)->post('/logout');

        // The remember cookie is expired on the response…
        $logout->assertCookieExpired($rememberCookie);

        // …and the remembered token is cycled in the database, so even a
        // cookie that somehow survives logout can never re-authenticate.
        $this->assertNotSame(
            $tokenAfterLogin,
            $user->fresh()->remember_token,
            'Logout must cycle the remember token — the old remember cookie must be dead.'
        );

        $this->assertGuest();
    }

    public function test_authenticated_html_responses_are_marked_no_store(): void
    {
        // Redirect responses emitted while authenticated (dashboard bounces
        // its verified users onward)…
        $verified = User::factory()->create();
        $this->actingAs($verified)
            ->get('/dashboard')
            ->assertHeader('Cache-Control', 'no-store, private');

        // …and real HTML documents rendered for authenticated users.
        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)
            ->get('/verify-email')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_logout_redirect_itself_is_marked_no_store(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_guest_responses_keep_their_default_caching_behavior(): void
    {
        // Public/guest traffic must be untouched. Symfony's default for
        // responses without an explicit directive is "no-cache, private" —
        // that default IS the untouched guest behavior; the point is that
        // it is NOT upgraded to no-store for guests.
        $this->get('/')->assertHeader('Cache-Control', 'no-cache, private');
    }

    public function test_authenticated_responses_are_not_cacheable_even_on_public_pages(): void
    {
        // A signed-in user browsing a public page gets personalized markup
        // (auth-aware nav). That render must never be cacheable either.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/pricing')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_419_page_renders_the_branded_error_view(): void
    {
        // Stale/double/expired-session logout submissions are answered by
        // Laravel's 419 handling with this product-branded view that always
        // offers a way forward (the bare framework page had no links).
        $this->view('errors.419')
            ->assertSee('Page Expired')
            ->assertSee(route('login'))
            ->assertSee(url('/'));
    }
}
