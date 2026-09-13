<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AlertDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_p17_1_first_alert_with_dedup_key_fires(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);
        Cache::flush();

        app(OperationalAlertService::class)->alert(
            'Test Alert',
            'First occurrence',
            'critical',
            'test_dedup_key_1'
        );

        // The first alert should fire at critical level.
        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Test Alert'))
            ->atLeast()
            ->once();

        // The dedup cache key should now exist.
        $this->assertTrue(Cache::has('alert:last_sent:test_dedup_key_1'));
    }

    public function test_audit_p17_1_duplicate_alert_within_ttl_is_suppressed(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);
        Cache::flush();

        $service = app(OperationalAlertService::class);

        $service->alert('Test Alert', 'First', 'critical', 'test_dedup_key_2');

        $service->alert('Test Alert', 'Second', 'critical', 'test_dedup_key_2');

        // Exactly ONE critical (the first alert) — the duplicate is suppressed.
        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'OperationalAlert: Test Alert'))
            ->once();

        // The suppression is traceable at debug level.
        Log::shouldHaveReceived('debug')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'suppressed duplicate alert'))
            ->atLeast()
            ->once();
    }

    public function test_audit_p17_1_different_dedup_keys_are_not_suppressed(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);
        Cache::flush();

        $service = app(OperationalAlertService::class);

        $service->alert('Alert A', 'First A', 'critical', 'test_dedup_key_A');

        $service->alert('Alert B', 'First B', 'critical', 'test_dedup_key_B');

        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Alert A'))
            ->once();
        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Alert B'))
            ->once();
    }

    public function test_audit_p17_1_no_dedup_key_means_no_suppression(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);
        Cache::flush();

        $service = app(OperationalAlertService::class);

        // Single spy, exact counts.
        $service->alert('No Dedup Alert', 'First', 'warning');

        $service->alert('No Dedup Alert', 'Second', 'warning');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'No Dedup Alert'))
            ->twice();
    }

    public function test_audit_p17_1_alert_refires_after_cache_cleared(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);
        Cache::flush();

        $service = app(OperationalAlertService::class);

        $service->alert('Re-fire Test', 'First', 'critical', 'test_dedup_refire');
        $service->alert('Re-fire Test', 'Second', 'critical', 'test_dedup_refire');

        // Simulate TTL expiry by clearing the cache.
        Cache::forget('alert:last_sent:test_dedup_refire');

        $service->alert('Re-fire Test', 'Third', 'critical', 'test_dedup_refire');

        // Exactly TWO criticals: first + third (second was suppressed).
        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Re-fire Test'))
            ->twice();
        Log::shouldHaveReceived('debug')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'suppressed duplicate alert'))
            ->once();
    }

    public function test_audit_p17_1_check_and_alert_dedup_on_persistent_condition(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);
        Cache::flush();

        // Create a persistent failing-jobs condition (above the 10-job warning threshold).
        for ($i = 0; $i < 15; $i++) {
            \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
                'uuid'           => 'test-uuid-' . $i,
                'connection'     => 'redis',
                'queue'          => 'default',
                'payload'        => json_encode(['job' => 'test']),
                'exception'      => 'Test exception',
                'failed_at'      => now(),
            ]);
        }

        $service = app(OperationalAlertService::class);

        // Single spy, exact counts.
        $service->checkAndAlert();
        $service->checkAndAlert();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Queue warning'))
            ->once();
    }

    public function test_audit_p17_1_dedup_ttls_are_severity_based(): void
    {
        $reflection = new \ReflectionClass(OperationalAlertService::class);
        $constants = $reflection->getConstant('DEDUP_TTL_SECONDS');

        $this->assertIsArray($constants);
        $this->assertEquals(1800, $constants['critical'], 'Critical dedup TTL should be 30 min (1800s)');
        $this->assertEquals(3600, $constants['error'], 'Error dedup TTL should be 1 hour (3600s)');
        $this->assertEquals(7200, $constants['warning'], 'Warning dedup TTL should be 2 hours (7200s)');
        $this->assertEquals(21600, $constants['info'], 'Info dedup TTL should be 6 hours (21600s)');
    }

    public function test_audit_p17_1_all_check_methods_pass_dedup_keys(): void
    {
        $source = file_get_contents(app_path('Services/OperationalAlertService.php'));

        $expectedDedupKeys = [
            'failed_jobs_critical',
            'failed_jobs_warning',
            'disk_usage_critical',
            'disk_usage_warning',
            'scheduler_stale',
            'queue_worker_stale',
            'backup_none_found:',
            'backup_stale:',
        ];

        foreach ($expectedDedupKeys as $key) {
            $this->assertStringContainsString(
                $key,
                $source,
                "OperationalAlertService should pass dedup key '{$key}' to alert()."
            );
        }
    }
}
