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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Rfc8058UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function welcome_email_emits_rfc8058_headers(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);

        $mail = new WelcomeEmail($user);

        $headers = $mail->headers();

        $this->assertArrayHasKey('List-Unsubscribe', $headers->text);
        $this->assertArrayHasKey('List-Unsubscribe-Post', $headers->text);
        $this->assertSame('List-Unsubscribe=One-Click', $headers->text['List-Unsubscribe-Post']);

        // The List-Unsubscribe URL must point at the one-click route, not the two-step route.
        $this->assertStringContainsString('/unsubscribe/one-click/', $headers->text['List-Unsubscribe']);
        $this->assertStringContainsString('signature=', $headers->text['List-Unsubscribe']);
    }

    #[Test]
    public function first_gallery_email_emits_rfc8058_headers(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        $mail = new FirstGalleryCreatedEmail($user, $gallery);
        $headers = $mail->headers();

        $this->assertArrayHasKey('List-Unsubscribe', $headers->text);
        $this->assertSame('List-Unsubscribe=One-Click', $headers->text['List-Unsubscribe-Post']);
    }

    #[Test]
    public function inactive_nudge_email_emits_rfc8058_headers(): void
    {
        $user = User::factory()->create();

        $mail = new InactiveUserNudge($user);
        $headers = $mail->headers();

        $this->assertArrayHasKey('List-Unsubscribe', $headers->text);
        $this->assertSame('List-Unsubscribe=One-Click', $headers->text['List-Unsubscribe-Post']);
    }

    #[Test]
    public function abandoned_cart_email_emits_rfc8058_headers(): void
    {
        $user = User::factory()->create();
        $pendingUpgrade = PendingUpgrade::factory()->create(['user_id' => $user->id]);

        $mail = new AbandonedCartEmail($user, $pendingUpgrade);
        $headers = $mail->headers();

        $this->assertArrayHasKey('List-Unsubscribe', $headers->text);
        $this->assertSame('List-Unsubscribe=One-Click', $headers->text['List-Unsubscribe-Post']);
    }

    #[Test]
    public function plan_upgraded_email_emits_rfc8058_headers(): void
    {
        $user = User::factory()->create();

        $mail = new PlanUpgradedEmail($user, 'pro', 'INV-2026-0001');
        $headers = $mail->headers();

        $this->assertArrayHasKey('List-Unsubscribe', $headers->text);
        $this->assertSame('List-Unsubscribe=One-Click', $headers->text['List-Unsubscribe-Post']);
    }

    #[Test]
    public function plan_expiring_email_emits_rfc8058_headers(): void
    {
        $user = User::factory()->create([
            'plan' => 'pro',
            'plan_expires_at' => now()->addDays(5),
        ]);

        $mail = new PlanExpiringSoon($user);
        $headers = $mail->headers();

        $this->assertArrayHasKey('List-Unsubscribe', $headers->text);
        $this->assertSame('List-Unsubscribe=One-Click', $headers->text['List-Unsubscribe-Post']);
    }

    #[Test]
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

    #[Test]
    public function one_click_post_endpoint_returns_200_without_csrf(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);

        $url = URL::signedRoute('unsubscribe.one-click.post', ['user' => $user->id]);

        // Use from() to bypass CSRF middleware entirely (no session).
        $response = $this->call('POST', $url);

        $response->assertStatus(200);
        $this->assertFalse($user->fresh()->marketing_consent, 'marketing_consent should be false after one-click unsubscribe');
    }

    #[Test]
    public function one_click_post_endpoint_rejects_unsigned_url(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);

        // Hit the route without a signature — signed middleware should 403.
        $response = $this->call('POST', route('unsubscribe.one-click.post', ['user' => $user->id], false));

        $response->assertStatus(403);
        $this->assertTrue($user->fresh()->marketing_consent, 'marketing_consent should be unchanged on rejected request');
    }

    #[Test]
    public function one_click_get_endpoint_unsubscribes_and_shows_confirmation(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);

        $url = URL::signedRoute('unsubscribe.one-click', ['user' => $user->id]);

        $response = $this->get($url);

        $response->assertStatus(200);
        $this->assertFalse($user->fresh()->marketing_consent, 'GET to one-click URL should also unsubscribe');
    }

    #[Test]
    public function one_click_endpoint_is_idempotent(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);

        $url = URL::signedRoute('unsubscribe.one-click.post', ['user' => $user->id]);

        $this->call('POST', $url)->assertStatus(200);
        $this->call('POST', $url)->assertStatus(200); // Second call should also succeed.
        $this->call('POST', $url)->assertStatus(200); // Third call too.

        $this->assertFalse($user->fresh()->marketing_consent);
    }

    #[Test]
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

    #[Test]
    public function email_layout_never_leaks_literal_newline_sequences_from_env_address(): void
    {
        // Env values set through the Coolify UI arrive with literal "\n"
        // sequences (unquoted env values are not escape-processed). The
        // config layer normalizes them, so the mangled form — a literal
        // backslash-n glued between address lines — must never reach a
        // rendered email footer.
        config(['app.business_address' => "Exospace Gallery\n27 Innovation Drive\nIslamabad 44000"]);

        $user = User::factory()->create();
        $rendered = (new WelcomeEmail($user))->render();

        $this->assertStringContainsString('Exospace Gallery', $rendered);
        $this->assertStringContainsString('27 Innovation Drive', $rendered);
        $this->assertStringContainsString('Islamabad 44000', $rendered);
    }

    #[Test]
    public function address_lines_helper_converts_literal_env_newlines(): void
    {
        $this->assertNull(address_lines(null));
        $this->assertSame("Exospace Gallery\nIslamabad 44000", address_lines('Exospace Gallery\nIslamabad 44000'));
        $this->assertSame("Exospace Gallery\nIslamabad 44000", address_lines("Exospace Gallery\nIslamabad 44000"));
    }

    #[Test]
    public function business_address_config_normalizes_literal_env_newlines(): void
    {
        // env() reads $_ENV/$_SERVER, so the literal-sequence value is planted
        // the way an unquoted .env entry would land there.
        $_ENV['EXOSPACE_BUSINESS_ADDRESS'] = 'Exospace Gallery\n27 Innovation Drive, Suite 4B\nIslamabad 44000';
        $_SERVER['EXOSPACE_BUSINESS_ADDRESS'] = $_ENV['EXOSPACE_BUSINESS_ADDRESS'];

        try {
            $config = require config_path('app.php');
        } finally {
            unset($_ENV['EXOSPACE_BUSINESS_ADDRESS'], $_SERVER['EXOSPACE_BUSINESS_ADDRESS']);
        }

        $this->assertSame(
            "Exospace Gallery\n27 Innovation Drive, Suite 4B\nIslamabad 44000",
            $config['business_address'],
        );
    }

    #[Test]
    public function email_layout_renders_unsubscribe_link_when_url_provided(): void
    {
        $user = User::factory()->create(['marketing_consent' => true]);
        $mail = new WelcomeEmail($user);

        $rendered = $mail->render();

        // The visible footer "Unsubscribe" link should render.
        $this->assertStringContainsString('Unsubscribe', $rendered);
        $this->assertStringContainsString('/unsubscribe/one-click/', $rendered);
    }

    #[Test]
    public function csrf_middleware_excludes_one_click_route(): void
    {
        $middleware = $this->app->make(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $except = $middleware->getExcludedPaths();

        $matches = false;
        foreach ($except as $pattern) {
            if (fnmatch($pattern, 'unsubscribe/one-click/42')) {
                $matches = true;
                break;
            }
        }
        $this->assertTrue($matches, 'unsubscribe/one-click/* must be excluded from CSRF verification');
    }
}
