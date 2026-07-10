<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AbandonedCartEmail;
use App\Mail\FirstGalleryCreatedEmail;
use App\Mail\InactiveUserNudge;
use App\Mail\PlanExpiringSoon;
use App\Mail\PlanUpgradedEmail;
use App\Mail\WelcomeEmail;
use App\Models\Gallery;
use App\Models\PendingUpgrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Iteration-007 regression tests for audit issues 9 + 10
 * (RFC 8058 one-click unsubscribe + CAN-SPAM postal address).
 *
 * Verifies:
 *   1. Every marketing mailable emits the List-Unsubscribe + List-Unsubscribe-Post
 *      headers required by Gmail/Yahoo (RFC 8058) since Feb 2024.
 *   2. The List-Unsubscribe URL points at the one-click unsubscribe route
 *      (NOT the two-step confirmation route).
 *   3. The List-Unsubscribe-Post value is exactly the RFC 8058-mandated literal.
 *   4. Each marketing mailable passes $unsubscribeUrl to the view so the
 *      visible footer "Unsubscribe" link renders.
 *   5. The one-click POST endpoint returns HTTP 200 (RFC 8058 §3).
 *   6. The one-click POST endpoint unsets marketing_consent.
 *   7. The one-click POST endpoint succeeds WITHOUT a CSRF token (the
 *      whole point of RFC 8058 — Gmail's request has no CSRF token).
 *   8. The one-click POST endpoint rejects unsigned URLs (403).
 *   9. The email layout renders the postal address when configured.
 */
class Rfc8058UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function welcome_email_emits_rfc8058_headers(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);

        $mail = new WelcomeEmail($user);
        $envelope = $mail->envelope();

        $this->assertArrayHasKey('List-Unsubscribe', $envelope->headers);
        $this->assertArrayHasKey('List-Unsubscribe-Post', $envelope->headers);
        $this->assertSame('List-Unsubscribe=One-Click', $envelope->headers['List-Unsubscribe-Post']);

        // The List-Unsubscribe URL must point at the one-click route, not the two-step route.
        $this->assertStringContainsString('/unsubscribe/one-click/', $envelope->headers['List-Unsubscribe']);
        $this->assertStringContainsString('signature=', $envelope->headers['List-Unsubscribe']);
    }

    /** @test */
    public function first_gallery_email_emits_rfc8058_headers(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        $mail = new FirstGalleryCreatedEmail($user, $gallery);
        $envelope = $mail->envelope();

        $this->assertArrayHasKey('List-Unsubscribe', $envelope->headers);
        $this->assertSame('List-Unsubscribe=One-Click', $envelope->headers['List-Unsubscribe-Post']);
    }

    /** @test */
    public function inactive_nudge_email_emits_rfc8058_headers(): void
    {
        $user = User::factory()->create();

        $mail = new InactiveUserNudge($user);
        $envelope = $mail->envelope();

        $this->assertArrayHasKey('List-Unsubscribe', $envelope->headers);
        $this->assertSame('List-Unsubscribe=One-Click', $envelope->headers['List-Unsubscribe-Post']);
    }

    /** @test */
    public function abandoned_cart_email_emits_rfc8058_headers(): void
    {
        $user = User::factory()->create();
        $pendingUpgrade = PendingUpgrade::factory()->create(['user_id' => $user->id]);

        $mail = new AbandonedCartEmail($user, $pendingUpgrade);
        $envelope = $mail->envelope();

        $this->assertArrayHasKey('List-Unsubscribe', $envelope->headers);
        $this->assertSame('List-Unsubscribe=One-Click', $envelope->headers['List-Unsubscribe-Post']);
    }

    /** @test */
    public function plan_upgraded_email_emits_rfc8058_headers(): void
    {
        $user = User::factory()->create();

        $mail = new PlanUpgradedEmail($user, 'pro', 'INV-2026-0001');
        $envelope = $mail->envelope();

        $this->assertArrayHasKey('List-Unsubscribe', $envelope->headers);
        $this->assertSame('List-Unsubscribe=One-Click', $envelope->headers['List-Unsubscribe-Post']);
    }

    /** @test */
    public function plan_expiring_email_emits_rfc8058_headers(): void
    {
        $user = User::factory()->create([
            'plan'             => 'pro',
            'plan_expires_at'  => now()->addDays(5),
        ]);

        $mail = new PlanExpiringSoon($user);
        $envelope = $mail->envelope();

        $this->assertArrayHasKey('List-Unsubscribe', $envelope->headers);
        $this->assertSame('List-Unsubscribe=One-Click', $envelope->headers['List-Unsubscribe-Post']);
    }

    /** @test */
    public function marketing_mailables_pass_unsubscribe_url_to_view(): void
    {
        $user = User::factory()->create();

        $mail = new WelcomeEmail($user);
        $content = $mail->content();

        // The 'with' array on Content is accessible via the public property.
        $this->assertNotEmpty($content->with);
        $this->assertArrayHasKey('unsubscribeUrl', $content->with);
        $this->assertStringContainsString('/unsubscribe/one-click/', $content->with['unsubscribeUrl']);
    }

    /** @test */
    public function one_click_post_endpoint_returns_200_without_csrf(): void
    {
        // RFC 8058 §3: response MUST be 2xx. RFC 8058 §2: the POST comes
        // from the email provider's machinery, not a browser — it will NOT
        // have a CSRF token. Our route must accept it.
        $user = User::factory()->create(['marketing_consent' => true]);

        $url = URL::signedRoute('unsubscribe.one-click.post', ['user' => $user->id]);

        // Use from() to bypass CSRF middleware entirely (no session).
        $response = $this->call('POST', $url);

        $response->assertStatus(200);
        $this->assertFalse($user->fresh()->marketing_consent, 'marketing_consent should be false after one-click unsubscribe');
    }

    /** @test */
    public function one_click_post_endpoint_rejects_unsigned_url(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);

        // Hit the route without a signature — signed middleware should 403.
        $response = $this->call('POST', route('unsubscribe.one-click.post', ['user' => $user->id], false));

        $response->assertStatus(403);
        $this->assertTrue($user->fresh()->marketing_consent, 'marketing_consent should be unchanged on rejected request');
    }

    /** @test */
    public function one_click_get_endpoint_unsubscribes_and_shows_confirmation(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);

        $url = URL::signedRoute('unsubscribe.one-click', ['user' => $user->id]);

        $response = $this->get($url);

        $response->assertStatus(200);
        $this->assertFalse($user->fresh()->marketing_consent, 'GET to one-click URL should also unsubscribe');
    }

    /** @test */
    public function one_click_endpoint_is_idempotent(): void
    {
        // Gmail may retry the POST on transient failures. The endpoint must
        // be idempotent — repeated POSTs after unsubscribe should still 200.
        $user = User::factory()->create(['marketing_consent' => true]);

        $url = URL::signedRoute('unsubscribe.one-click.post', ['user' => $user->id]);

        $this->call('POST', $url)->assertStatus(200);
        $this->call('POST', $url)->assertStatus(200); // Second call should also succeed.
        $this->call('POST', $url)->assertStatus(200); // Third call too.

        $this->assertFalse($user->fresh()->marketing_consent);
    }

    /** @test */
    public function email_layout_renders_postal_address_when_configured(): void
    {
        config(['app.business_address' => "Exospace Gallery\n123 Main St\nSan Francisco, CA 94101"]);

        $user = User::factory()->create();
        $mail = new WelcomeEmail($user);

        $rendered = $mail->render();

        // The address should appear (with newlines converted to <br> in the layout).
        $this->assertStringContainsString('Exospace Gallery', $rendered);
        $this->assertStringContainsString('123 Main St', $rendered);
        $this->assertStringContainsString('San Francisco', $rendered);
    }

    /** @test */
    public function email_layout_renders_unsubscribe_link_when_url_provided(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);
        $mail = new WelcomeEmail($user);

        $rendered = $mail->render();

        // The visible footer "Unsubscribe" link should render.
        $this->assertStringContainsString('Unsubscribe', $rendered);
        $this->assertStringContainsString('/unsubscribe/one-click/', $rendered);
    }

    /** @test */
    public function csrf_middleware_excludes_one_click_route(): void
    {
        // Verify the route is registered in the CSRF-except list.
        $middleware = $this->app->make(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $reflection = new \ReflectionClass($middleware);
        $property = $reflection->getProperty('except');
        $property->setAccessible(true);
        $except = $property->getValue($middleware);

        $matches = false;
        foreach ($except as $pattern) {
            if (fnmatch($pattern, 'unsubscribe/one-click/42')) {
                $matches = true;
                break;
            }
        }
        $this->assertTrue($matches, 'unsubscribe/one-click/* must be in ValidateCsrfToken::$except');
    }
}
