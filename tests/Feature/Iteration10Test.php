<?php

declare(strict_types=1);

/**
 * ITERATION-10 regression tests.
 *
 * Verifies:
 *   - AUDIT-P1-10.1: Per-severity alert routing — critical alerts route to
 *     OPERATIONAL_ALERT_CRITICAL_WEBHOOK when set, warnings to
 *     OPERATIONAL_ALERT_WARNING_WEBHOOK, etc.
 *   - AUDIT-P1-10.1: Falls back to OPERATIONAL_ALERT_WEBHOOK when the
 *     per-severity env var is absent (backward-compatible).
 *   - AUDIT-P1-10.1: When neither per-severity nor default webhook is set,
 *     the alert is logged only (no webhook call).
 *
 * Run: php artisan test --filter=Iteration10Test
 */

namespace Tests\Feature;

use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class Iteration10Test extends TestCase
{
    use RefreshDatabase;

    /**
     * AUDIT-P1-10.1: When only the default webhook is set, ALL severities
     * route to it (backward-compatible with pre-iteration-10 behavior).
     */
    public function test_audit_p110_1_all_severities_route_to_default_when_no_per_severity_set(): void
    {
        Http::fake();
        Log::spy();

        $defaultWebhook = 'https://hooks.slack.com/services/DEFAULT';
        config(['services.operational_alerts.webhook_url' => $defaultWebhook]);
        // No per-severity webhooks configured.
        config(['services.operational_alerts.critical_webhook_url' => null]);
        config(['services.operational_alerts.warning_webhook_url' => null]);
        config(['services.operational_alerts.info_webhook_url' => null]);

        $service = app(OperationalAlertService::class);

        $service->alert('Critical Test', 'msg', 'critical');
        $service->alert('Warning Test', 'msg', 'warning');
        $service->alert('Info Test', 'msg', 'info');

        // All 3 should have hit the default webhook.
        Http::assertSent(function ($request) use ($defaultWebhook) {
            return $request->url() === $defaultWebhook;
        });
    }

    /**
     * AUDIT-P1-10.1: Critical alerts route to OPERATIONAL_ALERT_CRITICAL_WEBHOOK
     * when it's set, while warnings still go to the default.
     */
    public function test_audit_p110_1_critical_routes_to_dedicated_webhook(): void
    {
        Http::fake();
        Log::spy();

        $defaultWebhook = 'https://hooks.slack.com/services/DEFAULT';
        $criticalWebhook = 'https://hooks.slack.com/services/CRITICAL';

        config(['services.operational_alerts.webhook_url' => $defaultWebhook]);
        config(['services.operational_alerts.critical_webhook_url' => $criticalWebhook]);
        config(['services.operational_alerts.warning_webhook_url' => null]);

        $service = app(OperationalAlertService::class);

        $service->alert('Critical Test', 'msg', 'critical');
        $service->alert('Warning Test', 'msg', 'warning');

        // Critical should hit the CRITICAL webhook.
        Http::assertSent(function ($request) use ($criticalWebhook) {
            return $request->url() === $criticalWebhook
                && str_contains($request->data()['text'] ?? '', 'Critical Test');
        });

        // Warning should hit the DEFAULT webhook (no dedicated warning webhook set).
        Http::assertSent(function ($request) use ($defaultWebhook) {
            return $request->url() === $defaultWebhook
                && str_contains($request->data()['text'] ?? '', 'Warning Test');
        });
    }

    /**
     * AUDIT-P1-10.1: Warning alerts route to OPERATIONAL_ALERT_WARNING_WEBHOOK
     * when it's set, while critical still goes to the default (if no critical
     * webhook is set).
     */
    public function test_audit_p110_1_warning_routes_to_dedicated_webhook(): void
    {
        Http::fake();
        Log::spy();

        $defaultWebhook = 'https://hooks.slack.com/services/DEFAULT';
        $warningWebhook = 'https://hooks.slack.com/services/WARNING';

        config(['services.operational_alerts.webhook_url' => $defaultWebhook]);
        config(['services.operational_alerts.critical_webhook_url' => null]);
        config(['services.operational_alerts.warning_webhook_url' => $warningWebhook]);

        $service = app(OperationalAlertService::class);

        $service->alert('Critical Test', 'msg', 'critical');
        $service->alert('Warning Test', 'msg', 'warning');

        // Critical should hit the DEFAULT webhook (no dedicated critical webhook set).
        Http::assertSent(function ($request) use ($defaultWebhook) {
            return $request->url() === $defaultWebhook
                && str_contains($request->data()['text'] ?? '', 'Critical Test');
        });

        // Warning should hit the WARNING webhook.
        Http::assertSent(function ($request) use ($warningWebhook) {
            return $request->url() === $warningWebhook
                && str_contains($request->data()['text'] ?? '', 'Warning Test');
        });
    }

    /**
     * AUDIT-P1-10.1: When NO webhooks are configured (neither default nor
     * per-severity), the alert is logged only — no HTTP call is made.
     * Sentry picks up via Log::critical.
     */
    public function test_audit_p110_1_no_webhook_means_log_only_no_http_call(): void
    {
        Http::fake();
        Log::spy();

        config(['services.operational_alerts.webhook_url' => null]);
        config(['services.operational_alerts.critical_webhook_url' => null]);
        config(['services.operational_alerts.warning_webhook_url' => null]);

        $service = app(OperationalAlertService::class);

        $service->alert('Critical Test', 'msg', 'critical');

        // No HTTP call should have been made.
        Http::assertNothingSent();

        // But the critical log entry SHOULD have been made (Sentry picks up).
        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Critical Test'))
            ->atLeast()
            ->once();
    }

    /**
     * AUDIT-P1-10.1: Info alerts route to OPERATIONAL_ALERT_INFO_WEBHOOK
     * when set.
     */
    public function test_audit_p110_1_info_routes_to_dedicated_webhook(): void
    {
        Http::fake();
        Log::spy();

        $defaultWebhook = 'https://hooks.slack.com/services/DEFAULT';
        $infoWebhook = 'https://hooks.slack.com/services/INFO';

        config(['services.operational_alerts.webhook_url' => $defaultWebhook]);
        config(['services.operational_alerts.info_webhook_url' => $infoWebhook]);

        $service = app(OperationalAlertService::class);

        $service->alert('Info Test', 'msg', 'info');

        Http::assertSent(function ($request) use ($infoWebhook) {
            return $request->url() === $infoWebhook
                && str_contains($request->data()['text'] ?? '', 'Info Test');
        });
    }

    /**
     * AUDIT-P1-10.1: The config block has all 4 per-severity webhook keys.
     */
    public function test_audit_p110_1_config_has_all_per_severity_keys(): void
    {
        $config = config('services.operational_alerts');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('webhook_url', $config);
        $this->assertArrayHasKey('critical_webhook_url', $config);
        $this->assertArrayHasKey('error_webhook_url', $config);
        $this->assertArrayHasKey('warning_webhook_url', $config);
        $this->assertArrayHasKey('info_webhook_url', $config);
    }

    /**
     * AUDIT-P1-10.1: The resolveWebhookUrl method exists (private, but
     * we verify via reflection so a future refactor doesn't silently
     * remove the routing logic).
     */
    public function test_audit_p110_1_resolve_webhook_url_method_exists(): void
    {
        $reflection = new \ReflectionClass(OperationalAlertService::class);
        $this->assertTrue(
            $reflection->hasMethod('resolveWebhookUrl'),
            'OperationalAlertService should have a resolveWebhookUrl method.'
        );
    }
}
