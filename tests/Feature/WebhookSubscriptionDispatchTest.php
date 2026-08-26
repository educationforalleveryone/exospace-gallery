<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ITERATION 10 — outbound webhook subscription dispatch fan-out.
 *
 * Coverage: the Iter-10 headline gap — OutboundWebhookService::dispatch
 * fans out per-event to every active webhook_subscriptions row
 * matching the event type. The env-var OUTBOUND_WEBHOOK_URL is
 * treated as an always-on "default" subscription (preserved
 * Iter-9 dispatch contract for fresh installs).
 *
 * Precedence (codified in the dispatch docblock):
 *   - 0 DB rows for an event  → only env (if configured)
 *   - ≥1 DB rows for an event  → each row + env (if configured)
 *   - neither configured        → silent-skip
 *
 * Per-subscription secrets override the global OUTBOUND_WEBHOOK_SECRET
 * when set; otherwise the global secret is used (preserves the Iter-9
 * HMAC contract for a fresh subscription that doesn't override).
 *
 * Run: php artisan test --filter=WebhookSubscriptionDispatchTest
 */
class WebhookSubscriptionDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const ENV_URL = 'https://env.example.com/exospace';
    private const ENV_SECRET = 'env-shared-secret';
    private const SUB_URL_A = 'https://sub-a.example.com/hook';
    private const SUB_URL_B = 'https://sub-b.example.com/hook';
    private const SUB_SECRET_A = 'per-sub-secret-a';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();

        // Configure the env-var default subscription so the fan-out
        // path includes it (the precedence rule). The silent-skip
        // path is asserted explicitly in its own test.
        config(['services.outbound_webhook.url' => self::ENV_URL]);
        config(['services.outbound_webhook.secret' => self::ENV_SECRET]);
        config(['services.operational_alerts.webhook_url' => null]);
    }

    public function test_dispatch_with_no_db_subscriptions_still_posts_to_env_url(): void
    {
        // Backward-compat: a fresh install with no DB subscriptions
        // behaves exactly like pre-Iter-10 (single env URL, single POST).
        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 1]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === self::ENV_URL
                && $request->header('X-Exospace-Event')[0] === 'gallery.published';
        });

        // Exactly one POST — the env URL. No fan-out (no DB subscriptions).
        Http::assertSentCount(1);
    }

    public function test_dispatch_fans_out_to_every_active_subscription_for_the_event(): void
    {
        // Two subscriptions for billing.recipient_added — both should receive.
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null, // falls back to global secret
            'is_active'  => true,
            'added_by'   => null,
        ]);
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_B,
            'secret'     => self::SUB_SECRET_A, // per-sub secret overrides global
            'is_active'  => true,
            'added_by'   => null,
        ]);
        // A subscription for a DIFFERENT event — must NOT receive this dispatch.
        WebhookSubscription::create([
            'event_type' => 'gallery.published',
            'target_url'  => 'https://wrong-event.example.com/hook',
            'secret'      => null,
            'is_active'   => true,
            'added_by'    => null,
        ]);

        \App\Services\OutboundWebhookService::dispatch('billing.recipient_added', ['recipient_email' => 'test@example.com']);

        // Env URL + sub A + sub B = 3 POSTs.
        Http::assertSentCount(3);

        $urls = [];
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use (&$urls) {
            $urls[] = $request->url();
            return true;
        });

        $this->assertContains(self::ENV_URL, $urls);
        $this->assertContains(self::SUB_URL_A, $urls);
        $this->assertContains(self::SUB_URL_B, $urls);
        $this->assertNotContains('https://wrong-event.example.com/hook', $urls);
    }

    public function test_per_subscription_secret_overrides_global_secret_for_hmac(): void
    {
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_removed',
            'target_url' => self::SUB_URL_A,
            'secret'     => self::SUB_SECRET_A,
            'is_active'  => true,
            'added_by'   => null,
        ]);

        \App\Services\OutboundWebhookService::dispatch('billing.recipient_removed', ['recipient_email' => 'gone@example.com']);

        // Sub A was signed with its per-sub secret (NOT the global secret).
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            if ($request->url() !== self::SUB_URL_A) {
                return false;
            }

            $sig = $request->header('X-Exospace-Signature')[0] ?? null;
            if (! $sig) {
                return false;
            }

            $body = $request->body();
            $expectedWithSubSecret = hash_hmac('sha256', $body, self::SUB_SECRET_A);
            $expectedWithEnvSecret = hash_hmac('sha256', $body, self::ENV_SECRET);

            return hash_equals($expectedWithSubSecret, $sig)
                && ! hash_equals($expectedWithEnvSecret, $sig);
        });
    }

    public function test_subscription_without_secret_falls_back_to_global_secret(): void
    {
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null, // explicit null → fall back to env secret
            'is_active'  => true,
            'added_by'   => null,
        ]);

        \App\Services\OutboundWebhookService::dispatch('billing.recipient_added', ['recipient_email' => 'fb@example.com']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            if ($request->url() !== self::SUB_URL_A) {
                return false;
            }

            $sig = $request->header('X-Exospace-Signature')[0] ?? null;
            if (! $sig) {
                return false;
            }

            // The signature matches the GLOBAL secret (env) — the per-sub
            // secret was null, so the global secret is used.
            $expected = hash_hmac('sha256', $request->body(), self::ENV_SECRET);
            return hash_equals($expected, $sig);
        });
    }

    public function test_paused_subscription_does_not_receive_dispatch(): void
    {
        // Active subscription — receives.
        WebhookSubscription::create([
            'event_type' => 'gallery.published',
            'target_url' => self::SUB_URL_A,
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => null,
        ]);
        // Paused subscription — does NOT receive.
        WebhookSubscription::create([
            'event_type' => 'gallery.published',
            'target_url' => self::SUB_URL_B,
            'secret'     => null,
            'is_active'  => false,
            'added_by'   => null,
        ]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 42]);

        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => $request->url() === self::ENV_URL);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => $request->url() === self::SUB_URL_A);
        Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request) => $request->url() === self::SUB_URL_B);
    }

    public function test_silent_skip_when_no_subscriptions_and_no_env_url_configured(): void
    {
        // Override the setUp config — fresh-install state.
        config(['services.outbound_webhook.url' => null]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 1]);

        Http::assertNothingSent();
    }

    public function test_dispatch_silently_skips_for_events_with_no_subscribers_and_no_env(): void
    {
        // Env is configured (so we exit the silent-skip-if-neither path),
        // but this event has zero subscriptions.
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => null,
        ]);

        // Dispatch a DIFFERENT event with no subscribers for it.
        \App\Services\OutboundWebhookService::dispatch('user.downgraded', ['user_id' => 99]);

        // Only the env URL received (the always-on default subscription).
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->url() === self::ENV_URL);
        Http::assertNotSent(fn (\Illuminate\Http\Client\Request $r) => $r->url() === self::SUB_URL_A);
    }
}
