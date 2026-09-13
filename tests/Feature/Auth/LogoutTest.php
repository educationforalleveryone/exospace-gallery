<?php

namespace Tests\Feature\Auth;

use App\Jobs\ProcessPlanDowngrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use Tests\TestCase;

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

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/profile')->assertRedirect(route('login'));
        $this->get('/billing')->assertRedirect(route('login'));
    }

    public function test_repeated_logout_fails_gracefully(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->post('/logout')->assertRedirect(route('login'));
        $this->get('/')->assertOk();
    }

    public function test_logout_deletes_the_remember_me_cookie_and_cycles_the_remember_token(): void
    {
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

        $rawRememberCookie = $login->getCookie($rememberCookie, decrypt: false);
        $rememberCookieValue = $rawRememberCookie?->getValue();
        $this->assertNotNull($rememberCookieValue);

        $logout = $this->withCookie($rememberCookie, $rememberCookieValue)->post('/logout');

        // The remember cookie is expired on the response…
        $logout->assertCookieExpired($rememberCookie);

        $this->assertNotSame(
            $tokenAfterLogin,
            $user->fresh()->remember_token,
            'Logout must cycle the remember token — the old remember cookie must be dead.'
        );

        $this->assertGuest();
    }

    public function test_authenticated_html_responses_are_marked_no_store(): void
    {
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
        $this->get('/')->assertHeader('Cache-Control', 'no-cache, private');
    }

    public function test_authenticated_responses_are_not_cacheable_even_on_public_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/pricing')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_419_page_renders_the_branded_error_view(): void
    {
        $this->view('errors.419')
            ->assertSee('Page Expired')
            ->assertSee(route('login'))
            ->assertSee(url('/'));
    }

    public function test_logout_is_not_hijacked_by_plan_expiry_downgrade(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'plan' => 'pro',
            'plan_expires_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
        $this->assertSame('free', $user->fresh()->plan, 'The expiry downgrade must still run.');
        Queue::assertPushed(ProcessPlanDowngrade::class);
    }

    public function test_logout_is_post_only_and_a_get_request_cannot_terminate_the_session(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/logout');

        $response->assertNotFound();
        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_ignores_redirect_input_and_returns_to_the_fixed_destination(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout', ['redirect' => 'https://evil.example/account'])
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_logout_with_a_session_record_that_no_longer_exists_fails_safely(): void
    {
        $user = User::factory()->create();

        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $login->assertRedirect();

        $rawSessionCookie = $login->getCookie(config('session.cookie'), decrypt: false)?->getValue();
        $this->assertNotNull($rawSessionCookie);

        // Simulate the session record vanishing server-side (idle expiry, GC, flush).
        $handler = $this->app['session.store']->getHandler();
        $storageProperty = new ReflectionProperty($handler, 'storage');
        $storageProperty->setValue($handler, []);
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('session');
        $this->app['auth']->forgetGuards();

        $this->withUnencryptedCookie(config('session.cookie'), $rawSessionCookie)
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_another_tab_using_the_same_session_cookie_is_rejected_after_logout(): void
    {
        $user = User::factory()->create();

        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $login->assertRedirect();

        $rawSessionCookie = $login->getCookie(config('session.cookie'), decrypt: false)?->getValue();
        $this->assertNotNull($rawSessionCookie);

        $this->withUnencryptedCookie(config('session.cookie'), $rawSessionCookie)
            ->post('/logout')
            ->assertRedirect('/');

        $this->withUnencryptedCookie(config('session.cookie'), $rawSessionCookie)
            ->get('/profile')
            ->assertRedirect(route('login'));

        $this->withUnencryptedCookie(config('session.cookie'), $rawSessionCookie)
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
