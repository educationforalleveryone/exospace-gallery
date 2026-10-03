<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AlertDeliveryFailureTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.slack.example/services/T/B/X';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.operational_alerts.webhook_url' => self::WEBHOOK]);
        config(['services.operational_alerts.critical_webhook_url' => null]);
        Cache::flush();
    }

    public function test_failed_webhook_delivery_does_not_record_the_dedup_key(): void
    {
        Log::spy();
        Http::fake([self::WEBHOOK => Http::response('server error', 500)]);

        app(OperationalAlertService::class)->alert(
            'Backup failed',
            'db backup exited 1',
            'critical',
            'backup_failed:db',
        );

        // A Slack 5xx must not count as a delivered alert — otherwise the
        // dedup key would suppress every retry for the whole critical TTL
        // (30 min) while Slack is down.
        $this->assertFalse(
            Cache::has('alert:last_sent:backup_failed:db'),
            'a failed webhook delivery must not mark the alert as sent',
        );

        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'failed to send webhook alert'))
            ->atLeast()
            ->once();
    }

    public function test_alert_fails_over_to_the_log_when_slack_is_unreachable(): void
    {
        Log::spy();
        Http::fake([self::WEBHOOK => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: connection refused')]);

        app(OperationalAlertService::class)->alert(
            'Backup failed',
            'db backup exited 1',
            'critical',
            'backup_failed:db',
        );

        // The transport failure must never escape alert() — the monitored
        // command keeps its own exit-code flow.
        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'OperationalAlert: Backup failed'))
            ->atLeast()
            ->once();
    }

    public function test_alerts_are_retried_on_the_next_check_while_slack_is_down(): void
    {
        Log::spy();
        Http::fake([self::WEBHOOK => Http::response('server error', 500)]);

        $service = app(OperationalAlertService::class);

        // Two 5-minute check cycles firing the same persistent condition:
        // both must attempt delivery — suppression only ever applies to a
        // confirmed sent alert.
        $service->alert('Backup failed', 'first cycle', 'critical', 'backup_failed:db');
        $service->alert('Backup failed', 'second cycle', 'critical', 'backup_failed:db');

        Http::assertSentCount(2);
    }

    public function test_successful_delivery_still_records_the_dedup_key(): void
    {
        Log::spy();
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);

        $service = app(OperationalAlertService::class);

        $service->alert('Backup failed', 'first cycle', 'critical', 'backup_failed:db');
        $service->alert('Backup failed', 'second cycle', 'critical', 'backup_failed:db');

        // Delivered once — the duplicate within the TTL is suppressed.
        Http::assertSentCount(1);
        $this->assertTrue(Cache::has('alert:last_sent:backup_failed:db'));

        Log::shouldHaveReceived('debug')
            ->withArgs(fn ($message) => str_contains($message, 'suppressed duplicate alert'))
            ->once();
    }
}
