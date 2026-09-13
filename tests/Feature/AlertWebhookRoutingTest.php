<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AlertWebhookRoutingTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_audit_p110_1_resolve_webhook_url_method_exists(): void
    {
        $reflection = new \ReflectionClass(OperationalAlertService::class);
        $this->assertTrue(
            $reflection->hasMethod('resolveWebhookUrl'),
            'OperationalAlertService should have a resolveWebhookUrl method.'
        );
    }
}
