<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ITERATION 11 — per-subscription delivery ledger persistence.
 *
 * Coverage: the OutboundWebhookService::dispatchSingle() path now
 * writes ONE row to the webhook_deliveries table at the end of
 * the retry loop (success OR retry-exhausted). The row captures
 * the FINAL state (http_status, attempt_count, success,
 * error_message, delivered_at) + the subscription_id (when
 * dispatched through the fan-out path) or null (when dispatched to
 * the env URL or a one-shot override URL).
 *
 * The ledger write is guarded by Schema::hasTable('webhook_
 * deliveries') and wrapped in try/catch — those code paths are
 * also covered here (fresh-install safe + dispatch-never-breaks-
 * on-ledger-write-failure).
 *
 * Backward-compat: the Iter-10 dispatch fan-out + per-subscription
 * secret + paused-subscription + silent-skip contract are
 * preserved verbatim. The Iter-10 WebhookSubscriptionDispatchTest
 * assertions (Http::assertSentCount + Http::assertNotSent) still
 * pass — the ledger write is a side-effect, not a change to the
 * dispatch path's HTTP behavior.
 *
 * Run: php artisan test --filter=WebhookDeliveryLedgerTest
 */
class WebhookDeliveryLedgerTest extends TestCase
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
        // ITERATION 11: Http::fake() is intentionally NOT called in
        // setUp. Laravel's Http::fake() MERGES stub callbacks (does
        // not replace), so a catch-all 200 installed in setUp would
        // swallow any URL-specific 500 override installed by an
        // individual test. Each test installs the exact fake it
        // needs (200 success / 500 retry-exhausted / exception).
        // Tests that don't make HTTP calls (silent-skip path) skip
        // the Http::fake call entirely.
        config(['services.outbound_webhook.url' => self::ENV_URL]);
        config(['services.outbound_webhook.secret' => self::ENV_SECRET]);
        config(['services.operational_alerts.webhook_url' => null]);
    }

    public function test_successful_dispatch_writes_ledger_row_with_http_status_and_attempt_count_1(): void
    {
        // Env URL returns 200 OK on first attempt → ledger row has
        // http_status=200, attempt_count=1, success=true,
        // error_message=null, subscription_id=null (env URL).
        Http::fake([self::ENV_URL => Http::response(['ok' => true], 200)]);

        // Suppress any operational-alerts path that might fire
        // outbound webhooks for unrelated reasons during the test.
        config(['services.operational_alerts.webhook_url' => null]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 1]);

        $row = WebhookDelivery::orderByDesc('id')->first();

        $this->assertNotNull($row, 'expected a ledger row to be written');
        $this->assertSame('gallery.published', $row->event_type);
        $this->assertSame(self::ENV_URL, $row->target_url);
        $this->assertNull($row->subscription_id, 'env URL dispatch has no DB subscription row');
        $this->assertSame(200, $row->http_status);
        $this->assertSame(1, $row->attempt_count);
        $this->assertTrue($row->success);
        $this->assertNull($row->error_message);
        $this->assertNotNull($row->delivered_at);
    }

    public function test_dispatch_to_db_subscription_threads_subscription_id_into_ledger_row(): void
    {
        Http::fake([
            self::ENV_URL  => Http::response(['ok' => true], 200),
            self::SUB_URL_A => Http::response(['ok' => true], 200),
        ]);
        config(['services.operational_alerts.webhook_url' => null]);

        $sub = WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => null,
        ]);

        \App\Services\OutboundWebhookService::dispatch('billing.recipient_added', ['recipient_email' => 'r@example.com']);

        // Two ledger rows: one for the env URL (subscription_id=null),
        // one for the DB subscription (subscription_id=$sub->id).
        $this->assertSame(2, WebhookDelivery::count());

        $envRow = WebhookDelivery::whereNull('subscription_id')->first();
        $subRow = WebhookDelivery::where('subscription_id', $sub->id)->first();

        $this->assertNotNull($envRow);
        $this->assertNotNull($subRow);
        $this->assertSame(self::ENV_URL, $envRow->target_url);
        $this->assertSame(self::SUB_URL_A, $subRow->target_url);
        $this->assertSame($sub->id, $subRow->subscription_id);
    }

    public function test_retry_exhausted_on_non_2xx_writes_failed_ledger_row_with_max_attempts(): void
    {
        // Env URL returns 500 on every attempt → all 3 retries
        // exhausted → ledger row has http_status=500,
        // attempt_count=3 (MAX_RETRIES), success=false,
        // error_message set to "Non-2xx response: HTTP 500".
        // Uses Http::fake(callback) form (returns 500 for EVERY
        // URL, not just self::ENV_URL) so the dispatch path's
        // retries ALL get 500 (Laravel's Http::fake MERGES stubs;
        // a callback installed with no prior catch-all is the only
        // reliable way to override).
        Http::fake(fn () => Http::response(['err' => 'down'], 500));
        config(['services.operational_alerts.webhook_url' => null]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 7]);

        $row = WebhookDelivery::orderByDesc('id')->first();

        $this->assertNotNull($row);
        $this->assertSame(500, $row->http_status);
        $this->assertSame(\App\Services\OutboundWebhookService::MAX_RETRIES, $row->attempt_count);
        $this->assertFalse($row->success);
        $this->assertNotNull($row->error_message);
        $this->assertStringContainsString('500', $row->error_message);
    }

    public function test_connection_failure_writes_ledger_row_with_null_http_status_and_exception_message(): void
    {
        // Http::fake with a callback that throws → all retries
        // threw → ledger row has http_status=null (no response
        // object ever), attempt_count=MAX_RETRIES, success=false,
        // error_message from the exception.
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });
        config(['services.operational_alerts.webhook_url' => null]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 99]);

        $row = WebhookDelivery::orderByDesc('id')->first();

        $this->assertNotNull($row);
        $this->assertNull($row->http_status, 'all attempts threw exceptions — no HTTP status recorded');
        $this->assertSame(\App\Services\OutboundWebhookService::MAX_RETRIES, $row->attempt_count);
        $this->assertFalse($row->success);
        $this->assertNotNull($row->error_message);
        $this->assertStringContainsString('Connection refused', $row->error_message);
    }

    public function test_silent_skip_when_no_subscribers_writes_no_ledger_row(): void
    {
        // Override the setUp env URL → fresh-install state.
        config(['services.outbound_webhook.url' => null]);
        config(['services.operational_alerts.webhook_url' => null]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 1]);

        // Silent-skip means NO dispatch attempted → NO ledger row.
        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_ledger_write_failure_does_not_break_dispatch_path(): void
    {
        // Drop the webhook_deliveries table mid-test to simulate a
        // ledger write failure (the Schema::hasTable guard catches
        // this case explicitly — the dispatch path is preserved
        // verbatim, the operator just loses the triage row).
        Http::fake([self::ENV_URL => Http::response(['ok' => true], 200)]);

        Schema::drop('webhook_deliveries');

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 5]);

        // The dispatch still happened — the receiver got the webhook.
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->url() === self::ENV_URL);

        // No ledger row was written (the table doesn't exist), and
        // the dispatch path didn't throw.
        $this->assertFalse(Schema::hasTable('webhook_deliveries'));
    }

    public function test_paused_subscription_writes_no_ledger_row_for_that_subscription(): void
    {
        // A paused subscription does not receive dispatch → no
        // ledger row for it. The env URL still receives + writes
        // its row (env is always-on regardless of DB state).
        Http::fake([
            self::ENV_URL  => Http::response(['ok' => true], 200),
            self::SUB_URL_A => Http::response(['ok' => true], 200),
        ]);

        $sub = WebhookSubscription::create([
            'event_type' => 'gallery.published',
            'target_url' => self::SUB_URL_A,
            'secret'     => null,
            'is_active'  => false, // PAUSED — no dispatch, no ledger row
            'added_by'   => null,
        ]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 42]);

        // Only the env URL row — the paused subscription got no
        // dispatch and therefore no ledger row.
        $this->assertSame(1, WebhookDelivery::count());
        $this->assertSame(0, WebhookDelivery::where('subscription_id', $sub->id)->count());
    }

    public function test_one_off_override_url_dispatch_writes_ledger_row_with_null_subscription_id(): void
    {
        // The direct $url override path bypasses the subscription
        // fan-out entirely. The ledger row captures this path with
        // subscription_id=null (no DB subscription row).
        Http::fake(['https://override.example.com/hook' => Http::response(['ok' => true], 200)]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 1], 'https://override.example.com/hook');

        $row = WebhookDelivery::orderByDesc('id')->first();

        $this->assertNotNull($row);
        $this->assertSame('https://override.example.com/hook', $row->target_url);
        $this->assertNull($row->subscription_id);
        $this->assertTrue($row->success);
    }
}
