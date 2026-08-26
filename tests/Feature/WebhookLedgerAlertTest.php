<?php

namespace Tests\Feature;

use App\Models\ProcessedWebhook;
use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ITERATION 5 — failed-webhook pile-up alerting.
 *
 * A failed processed_webhooks row (a billing event 2Checkout sent and we
 * could not apply) previously surfaced only as a log line and a passive
 * Billing Review tile — webhook processing is synchronous HTTP, so it
 * never reached failed_jobs, and nothing paged anyone. These tests pin:
 *   1. checkWebhookLedger thresholds: >5 warning, >20 critical
 *   2. Stuck 'processing' rows: >30 min warning, >2h critical
 *   3. Healthy ledger → silence
 *   4. /health exposes billing_webhooks: ok / warning (200) / degraded (503)
 */
class WebhookLedgerAlertTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.example.test/services/alerts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.operational_alerts.webhook_url' => self::WEBHOOK]);
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function seedWebhookRow(string $status, ?string $updatedAt = null): void
    {
        ProcessedWebhook::create([
            'message_id'   => 'MSG-' . uniqid(),
            'message_type' => 'REFUND_ISSUED',
            'invoice_id'   => 'INV-' . uniqid(),
            'payload'      => ['message_type' => 'REFUND_ISSUED'],
            'status'       => $status,
            'updated_at'   => $updatedAt ?? now(),
        ]);
    }

    private function sentBodies(): array
    {
        $bodies = [];
        foreach (Http::recorded() as [$request, $response]) {
            if ($request->url() === self::WEBHOOK) {
                $bodies[] = $request->body();
            }
        }

        return $bodies;
    }

    // ── Failed-row thresholds ───────────────────────────────────────────

    public function test_healthy_ledger_stays_silent(): void
    {
        Http::fake();
        for ($i = 0; $i < 3; $i++) {
            $this->seedWebhookRow('processed');
        }

        app(OperationalAlertService::class)->checkWebhookLedger();

        $this->assertSame([], $this->sentBodies(), 'a handful of healthy webhooks must not alert');
    }

    public function test_failed_rows_above_five_alert_at_warning(): void
    {
        Http::fake();
        for ($i = 0; $i < 6; $i++) {
            $this->seedWebhookRow('failed');
        }

        app(OperationalAlertService::class)->checkWebhookLedger();

        $bodies = $this->sentBodies();
        $this->assertCount(1, $bodies);
        $this->assertStringContainsString('Failed webhooks accumulating', $bodies[0]);
        $this->assertStringContainsString('Billing Review', $bodies[0], 'alert copy points at the replay surface');
    }

    public function test_failed_rows_above_twenty_alert_at_critical(): void
    {
        Http::fake();
        for ($i = 0; $i < 21; $i++) {
            $this->seedWebhookRow('failed');
        }

        app(OperationalAlertService::class)->checkWebhookLedger();

        $bodies = $this->sentBodies();
        $this->assertCount(1, $bodies);
        $this->assertStringContainsString('Failed webhooks piling up', $bodies[0]);
        $this->assertStringContainsString('CRITICAL', $bodies[0]);
    }

    // ── Stuck-processing thresholds ─────────────────────────────────────

    public function test_stuck_processing_rows_alert_at_warning_after_thirty_minutes(): void
    {
        Http::fake();
        // Fresh processing row (recent) — must NOT trip the stuck check...
        $this->seedWebhookRow('processing', now()->subMinutes(5));
        // ...old enough to trip it.
        $this->seedWebhookRow('processing', now()->subMinutes(40));

        app(OperationalAlertService::class)->checkWebhookLedger();

        $bodies = $this->sentBodies();
        $this->assertCount(1, $bodies, 'only the 40-minute-old row alerts');
        $this->assertStringContainsString('stalled', $bodies[0]);
        $this->assertStringContainsString('WARNING', $bodies[0]);
    }

    public function test_stuck_processing_rows_alert_at_critical_after_two_hours(): void
    {
        Http::fake();
        $this->seedWebhookRow('processing', now()->subHours(3));

        app(OperationalAlertService::class)->checkWebhookLedger();

        $bodies = $this->sentBodies();
        $this->assertCount(1, $bodies);
        $this->assertStringContainsString('Webhooks stuck in processing', $bodies[0]);
        $this->assertStringContainsString('CRITICAL', $bodies[0]);
    }

    public function test_failed_alerts_are_deduplicated_on_repeat_checks(): void
    {
        Http::fake();
        for ($i = 0; $i < 6; $i++) {
            $this->seedWebhookRow('failed');
        }

        $service = app(OperationalAlertService::class);
        $service->checkWebhookLedger();
        $service->checkWebhookLedger(); // same condition 5 minutes later

        $this->assertCount(1, $this->sentBodies(), 'the 5-minute cadence must not spam a persistent condition');
    }

    // ── /health integration ─────────────────────────────────────────────

    public function test_health_reports_billing_webhooks_ok_when_healthy(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->seedWebhookRow('failed');
        }

        $response = $this->getJson('/health');

        $response->assertOk();
        $this->assertSame('ok', $response->json('checks.billing_webhooks.status'));
        $this->assertSame(2, $response->json('checks.billing_webhooks.failed_webhooks'));
    }

    public function test_health_reports_warning_but_stays_200_above_five_failed(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->seedWebhookRow('failed');
        }

        $response = $this->getJson('/health');

        // /health must not flap uptime monitors on a handful of retriable
        // failures — the Slack channel is the paging surface for warnings.
        $response->assertOk();
        $this->assertSame('warning', $response->json('checks.billing_webhooks.status'));
    }

    public function test_health_goes_degraded_above_twenty_failed(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->seedWebhookRow('failed');
        }

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $this->assertSame('degraded', $response->json('checks.billing_webhooks.status'));
        $this->assertSame('degraded', $response->json('status'));
    }
}
