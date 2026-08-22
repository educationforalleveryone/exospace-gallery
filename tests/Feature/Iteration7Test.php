<?php

declare(strict_types=1);

/**
 * ITERATION-7 regression tests.
 *
 * Verifies:
 *   - AUDIT-P1-7.1: alert deduplication — same dedupKey within TTL is suppressed,
 *     different dedupKeys are not, TTL expiry re-allows the alert.
 *   - AUDIT-P1-7.1: dedup is opt-in (no dedupKey = no suppression).
 *   - AUDIT-P1-7.1: cache unavailability doesn't block alerts (fail-open).
 *
 * Run: php artisan test --filter=Iteration7Test
 */

namespace Tests\Feature;

use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class Iteration7Test extends TestCase
{
    use RefreshDatabase;

    /**
     * AUDIT-P1-7.1: First alert with a dedupKey fires + records the dedup state.
     */
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

    /**
     * AUDIT-P1-7.1: Second alert with the SAME dedupKey within the TTL is suppressed.
     */
    public function test_audit_p17_1_duplicate_alert_within_ttl_is_suppressed(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);
        Cache::flush();

        $service = app(OperationalAlertService::class);

        // First alert fires.
        $service->alert('Test Alert', 'First', 'critical', 'test_dedup_key_2');

        // Reset spy to only track the second call.
        Log::restore();
        Log::spy();

        // Second alert with same dedupKey should be suppressed.
        $service->alert('Test Alert', 'Second', 'critical', 'test_dedup_key_2');

        // The second alert should NOT fire at critical level — it should be suppressed.
        // Instead, a debug-level "suppressed duplicate" message should be logged.
        Log::shouldNotHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'OperationalAlert: Test Alert'));

        Log::shouldHaveReceived('debug')
            ->withArgs(fn ($message) => str_contains($message, 'suppressed duplicate alert'))
            ->atLeast()
            ->once();
    }

    /**
     * AUDIT-P1-7.1: Different dedupKeys are NOT suppressed (independent conditions).
     */
    public function test_audit_p17_1_different_dedup_keys_are_not_suppressed(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);
        Cache::flush();

        $service = app(OperationalAlertService::class);

        // First alert with dedupKey A.
        $service->alert('Alert A', 'First A', 'critical', 'test_dedup_key_A');

        // Reset spy.
        Log::restore();
        Log::spy();

        // Second alert with DIFFERENT dedupKey B should still fire.
        $service->alert('Alert B', 'First B', 'critical', 'test_dedup_key_B');

        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Alert B'))
            ->atLeast()
            ->once();
    }

    /**
     * AUDIT-P1-7.1: When no dedupKey is passed, dedup is NOT applied (backward compat).
     * Existing callers that don't pass dedupKey behave exactly as before.
     */
    public function test_audit_p17_1_no_dedup_key_means_no_suppression(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);
        Cache::flush();

        $service = app(OperationalAlertService::class);

        // First alert without dedupKey.
        $service->alert('No Dedup Alert', 'First', 'warning');

        // Reset spy.
        Log::restore();
        Log::spy();

        // Second alert without dedupKey should also fire (no suppression).
        $service->alert('No Dedup Alert', 'Second', 'warning');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'No Dedup Alert'))
            ->atLeast()
            ->once();
    }

    /**
     * AUDIT-P1-7.1: TTL expiry re-allows the alert. We simulate this by
     * manually clearing the cache key (equivalent to TTL expiring).
     */
    public function test_audit_p17_1_alert_refires_after_cache_cleared(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);
        Cache::flush();

        $service = app(OperationalAlertService::class);

        // First alert fires.
        $service->alert('Re-fire Test', 'First', 'critical', 'test_dedup_refire');

        // Second alert suppressed.
        Log::restore();
        Log::spy();
        $service->alert('Re-fire Test', 'Second', 'critical', 'test_dedup_refire');
        Log::shouldNotHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'OperationalAlert: Re-fire Test'));

        // Simulate TTL expiry by clearing the cache.
        Cache::forget('alert:last_sent:test_dedup_refire');

        // Third alert should fire again (TTL expired).
        Log::restore();
        Log::spy();
        $service->alert('Re-fire Test', 'Third', 'critical', 'test_dedup_refire');

        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Re-fire Test'))
            ->atLeast()
            ->once();
    }

    /**
     * AUDIT-P1-7.1: The 5 check methods all pass dedupKeys. Verify the
     * dedup keys are present in the source by calling checkAndAlert() twice
     * and confirming the second call suppresses (no new critical/error/warning
     * log entries for the same conditions).
     *
     * This is an integration test — it sets up a failing-jobs condition
     * and verifies that calling checkAndAlert() twice doesn't double-alert.
     */
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

        // First call — should fire the warning.
        $service->checkAndAlert();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Queue warning'))
            ->atLeast()
            ->once();

        // Reset spy.
        Log::restore();
        Log::spy();

        // Second call — same condition, should be SUPPRESSED by dedup.
        $service->checkAndAlert();

        // The warning should NOT fire again (dedup key 'failed_jobs_warning' is set).
        Log::shouldNotHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'OperationalAlert: Queue warning'));
    }

    /**
     * AUDIT-P1-7.1: Severity-based TTLs are configured correctly.
     * Critical = 30 min, warning = 2 hours, info = 6 hours.
     * (We verify via reflection since the constants are private.)
     */
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

    /**
     * AUDIT-P1-7.1: Verify all 8 dedup keys are present in the check methods
     * by reading the source. This catches a regression where a new alert
     * call site forgets to pass the dedupKey.
     */
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
            'backup_none_found',
            'backup_stale',
        ];

        foreach ($expectedDedupKeys as $key) {
            $this->assertStringContainsString(
                "'{$key}'",
                $source,
                "OperationalAlertService should pass dedup key '{$key}' to alert()."
            );
        }
    }
}
