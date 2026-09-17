<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * CSRF + cross-origin request boundary checks.
 *
 * ValidateCsrfToken skips verification while the app runs unit tests, so the
 * cases that exercise real enforcement flip the app env away from "testing"
 * (same technique as WebhookSecurityTest).
 */
class CsrfCrossOriginBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const FORGED_ORIGIN = 'https://unintended.example';

    private function withCsrfEnforced(): void
    {
        $this->app['env'] = 'production';
    }

    // ── Valid same-origin request ────────────────────────────────────────

    public function test_session_action_with_matching_csrf_token_succeeds(): void
    {
        $this->withCsrfEnforced();
        $user = User::factory()->create();
        $token = 'boundary-token-1234567890abcdef';

        $response = $this->actingAs($user)
            ->withSession(['_token' => $token])
            ->post('/logout', ['_token' => $token]);

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_ajax_session_action_with_valid_csrf_header_succeeds(): void
    {
        $this->withCsrfEnforced();
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['is_active' => true]);
        $token = 'boundary-token-1234567890abcdef';

        $response = $this->withSession(['_token' => $token])
            ->post("/gallery/{$gallery->id}/track", [
                'event' => 'view',
                'session_token' => 'sess-abc123',
            ], ['X-CSRF-TOKEN' => $token]);

        $response->assertOk();
    }

    // ── Missing / invalid CSRF token ─────────────────────────────────────

    public function test_session_action_without_csrf_token_is_rejected(): void
    {
        $this->withCsrfEnforced();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        // A 419 means the logout never ran — the session survives untouched.
        $response->assertStatus(419);
    }

    public function test_session_action_with_forged_csrf_token_is_rejected(): void
    {
        $this->withCsrfEnforced();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['_token' => 'real-session-token'])
            ->post('/logout', ['_token' => 'attacker-guessed-token']);

        $response->assertStatus(419);
    }

    // ── Cross-origin state-changing request ──────────────────────────────

    public function test_forged_cross_origin_browser_request_cannot_perform_protected_action(): void
    {
        $this->withCsrfEnforced();
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['is_active' => true]);

        // A forged POST as a victim browser would send it: session cookie
        // present, no way to know the CSRF token.
        $response = $this->actingAs($user)->post('/logout');

        $response->assertStatus(419);

        $response = $this->actingAs($user)
            ->post("/gallery/{$gallery->id}/track", [
                'event' => 'view',
                'session_token' => 'sess-abc123',
            ], ['Origin' => self::FORGED_ORIGIN]);

        $response->assertStatus(419);
    }

    // ── GET safety ───────────────────────────────────────────────────────

    public function test_billing_upgrade_get_creates_no_state(): void
    {
        config(['services.2checkout.product_id_pro' => 'PRO-001']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/billing/upgrade/pro');

        $response->assertOk();
        $this->assertDatabaseCount('pending_upgrades', 0);
    }

    public function test_billing_upgrade_post_without_token_is_rejected(): void
    {
        $this->withCsrfEnforced();
        config(['services.2checkout.product_id_pro' => 'PRO-001']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/billing/upgrade/pro');

        $response->assertStatus(419);
        $this->assertDatabaseCount('pending_upgrades', 0);
    }

    public function test_no_get_route_mutates_billing_state_via_forged_navigation(): void
    {
        config(['services.2checkout.product_id_pro' => 'PRO-001']);
        $user = User::factory()->create();

        // SameSite=Lax still delivers session cookies on top-level GET
        // navigations, so a cross-site <img>/<a> must not be able to spend
        // anything — only the confirmation page may render.
        $response = $this->actingAs($user)
            ->get('/billing/upgrade/pro', ['Referer' => self::FORGED_ORIGIN]);

        $response->assertOk();
        $this->assertDatabaseCount('pending_upgrades', 0);
    }

    // ── Livewire ─────────────────────────────────────────────────────────

    public function test_livewire_update_endpoint_sits_inside_the_csrf_boundary(): void
    {
        $this->withCsrfEnforced();

        $response = $this->post(\Livewire\Mechanisms\HandleRequests\EndpointResolver::updatePath(), []);

        $response->assertStatus(419);
    }

    // ── Intentional non-browser endpoints ────────────────────────────────

    public function test_2checkout_webhook_stays_csrf_exempt_and_signature_gated(): void
    {
        config(['services.2checkout.secret_word' => 'TESTSECRET']);
        $this->app['env'] = 'production';

        // No CSRF token: must reach the signature check, not a 419.
        $response = $this->postJson('/webhooks/2checkout', [
            'sale_id' => '9015',
            'vendor_id' => '255522032322',
            'invoice_id' => 'INV-1',
            'md5_hash' => str_repeat('A', 32),
        ]);

        $response->assertStatus(403);
    }

    public function test_rfc8058_one_click_unsubscribe_stays_functional_without_csrf(): void
    {
        $this->app['env'] = 'production';
        $user = User::factory()->create(['marketing_consent' => true]);

        $url = URL::temporarySignedRoute('unsubscribe.one-click.post', now()->addDays(7), ['user' => $user->id]);

        $response = $this->post($url);

        $response->assertStatus(200);
        $this->assertFalse($user->fresh()->marketing_consent);
    }

    // ── CORS ─────────────────────────────────────────────────────────────

    public function test_public_api_read_endpoint_is_cross_origin_readable_without_credentials(): void
    {
        $response = $this->get('/api/v1/galleries', ['Origin' => self::FORGED_ORIGIN]);

        $response->assertOk();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $response->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_public_api_preflight_is_answered(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/galleries', [], [], [], [
            'HTTP_ORIGIN' => self::FORGED_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response->assertSuccessful();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_sanctum_csrf_cookie_route_is_not_cross_origin_reachable(): void
    {
        // No SPA cookie-auth flow exists; the CSRF-cookie issuer must not be
        // exposed to arbitrary origins.
        $response = $this->get('/sanctum/csrf-cookie', ['Origin' => self::FORGED_ORIGIN]);

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_sanctum_csrf_cookie_route_remains_same_origin_usable(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertNoContent(204);
        $this->assertTrue(
            $response->headers->has('Set-Cookie'),
            'Same-origin SPA clients must still be able to obtain the XSRF cookie.'
        );
    }

    public function test_web_routes_receive_no_cors_headers(): void
    {
        $response = $this->get('/contact', ['Origin' => self::FORGED_ORIGIN]);

        $response->assertOk();
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
