<?php

declare(strict_types=1);

/**
 * ITERATION-11 regression tests.
 *
 * Verifies:
 *   - AUDIT-P2-11.1: /metrics?format=prometheus returns Prometheus text exposition format
 *   - AUDIT-P2-11.1: Default format remains JSON (backward-compatible)
 *   - AUDIT-P2-11.1: Prometheus format includes all expected metrics (queue, db, disk, memory)
 *   - AUDIT-P2-11.1: Content-Type is text/plain; version=0.0.4
 *   - AUDIT-P2-11.1: Token gating still applies to Prometheus format
 *   - AUDIT-P2-11.2: memory_peak_mb uses memory_get_peak_usage (not memory_get_usage)
 *
 * Run: php artisan test --filter=Iteration11Test
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Iteration11Test extends TestCase
{
    use RefreshDatabase;

    /**
     * AUDIT-P2-11.1: Default format (no format param) returns JSON.
     */
    public function test_audit_p211_1_default_format_returns_json(): void
    {
        config(['app.metrics_token' => 'test-token']);

        $response = $this->get('/metrics?token=test-token');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
    }

    /**
     * AUDIT-P2-11.1: ?format=prometheus returns text/plain with Prometheus
     * exposition format.
     */
    public function test_audit_p211_1_prometheus_format_returns_text_plain(): void
    {
        config(['app.metrics_token' => 'test-token']);

        $response = $this->get('/metrics?token=test-token&format=prometheus');

        $response->assertOk();
        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString('text/plain', $contentType, 'Content-Type should be text/plain');
        $this->assertStringContainsString('version=0.0.4', $contentType, 'Content-Type should include Prometheus version');
    }

    /**
     * AUDIT-P2-11.1: Prometheus output includes HELP + TYPE comments for each metric.
     */
    public function test_audit_p211_1_prometheus_includes_help_and_type_comments(): void
    {
        config(['app.metrics_token' => 'test-token']);

        $response = $this->get('/metrics?token=test-token&format=prometheus');

        $body = $response->getContent();
        $this->assertStringContainsString('# HELP exospace_queue_failed_jobs', $body);
        $this->assertStringContainsString('# TYPE exospace_queue_failed_jobs gauge', $body);
        $this->assertStringContainsString('# HELP exospace_queue_pending_jobs', $body);
        $this->assertStringContainsString('# TYPE exospace_queue_pending_jobs gauge', $body);
        $this->assertStringContainsString('# HELP exospace_db_status', $body);
        $this->assertStringContainsString('# TYPE exospace_db_status gauge', $body);
        $this->assertStringContainsString('# HELP exospace_php_memory_usage_bytes', $body);
        $this->assertStringContainsString('# TYPE exospace_php_memory_usage_bytes gauge', $body);
    }

    /**
     * AUDIT-P2-11.1: Prometheus output includes actual metric values (not just comments).
     */
    public function test_audit_p211_1_prometheus_includes_metric_values(): void
    {
        config(['app.metrics_token' => 'test-token']);

        // Insert a failed job so the count is non-zero.
        DB::table('failed_jobs')->insert([
            'uuid'       => 'test-uuid-prometheus',
            'connection' => 'redis',
            'queue'      => 'default',
            'payload'    => json_encode(['job' => 'test']),
            'exception'  => 'Test exception',
            'failed_at'  => now(),
        ]);

        $response = $this->get('/metrics?token=test-token&format=prometheus');

        $body = $response->getContent();
        $this->assertStringContainsString('exospace_queue_failed_jobs', $body);
        $this->assertStringContainsString('exospace_db_status', $body);
        $this->assertStringContainsString('exospace_php_memory_usage_bytes', $body);

        // The failed_jobs metric value should be >= 1 (we inserted one).
        $this->assertMatchesRegularExpression('/exospace_queue_failed_jobs\s+\d+/', $body);
        $this->assertMatchesRegularExpression('/exospace_queue_failed_jobs\s+[1-9]/', $body, 'Failed jobs should be >= 1');
    }

    /**
     * AUDIT-P2-11.1: Token gating still applies to Prometheus format.
     * Without a valid token, the endpoint returns 404.
     */
    public function test_audit_p211_1_prometheus_still_requires_token(): void
    {
        config(['app.metrics_token' => 'correct-token']);

        // No token → 404
        $this->get('/metrics?format=prometheus')->assertStatus(404);

        // Wrong token → 404
        $this->get('/metrics?token=wrong&format=prometheus')->assertStatus(404);

        // Correct token → 200
        $this->get('/metrics?token=correct-token&format=prometheus')->assertOk();
    }

    /**
     * AUDIT-P2-11.1: When METRICS_TOKEN is unset, Prometheus format also
     * fails closed (404) — same as JSON format.
     */
    public function test_audit_p211_1_prometheus_fails_closed_when_token_unset(): void
    {
        config(['app.metrics_token' => null]);

        $this->get('/metrics?format=prometheus')->assertStatus(404);
        $this->get('/metrics?token=anything&format=prometheus')->assertStatus(404);
    }

    /**
     * AUDIT-P2-11.1: Disk metrics are exposed when the storage path is accessible.
     */
    public function test_audit_p211_1_prometheus_includes_disk_metrics_when_available(): void
    {
        config(['app.metrics_token' => 'test-token']);

        $response = $this->get('/metrics?token=test-token&format=prometheus');
        $body = $response->getContent();

        // Disk metrics should be present (the test environment has a real disk).
        $this->assertStringContainsString('exospace_disk_free_bytes', $body);
        $this->assertStringContainsString('exospace_disk_total_bytes', $body);
        $this->assertStringContainsString('exospace_disk_used_ratio', $body);
    }

    /**
     * AUDIT-P2-11.2: memory_peak_mb should use memory_get_peak_usage,
     * not memory_get_usage (which was a pre-existing bug).
     */
    public function test_audit_p211_2_memory_peak_uses_peak_function(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/MetricsController.php'));

        $this->assertStringContainsString(
            'memory_get_peak_usage(true)',
            $source,
            'AUDIT-P2-11.2: appMetrics() should use memory_get_peak_usage for memory_peak_mb'
        );

        // Verify the peak value is actually exposed in Prometheus output
        config(['app.metrics_token' => 'test-token']);
        $response = $this->get('/metrics?token=test-token&format=prometheus');
        $body = $response->getContent();

        $this->assertStringContainsString('exospace_php_memory_peak_bytes', $body);
        $this->assertStringContainsString('# HELP exospace_php_memory_peak_bytes', $body);
        $this->assertStringContainsString('# TYPE exospace_php_memory_peak_bytes gauge', $body);
    }

    /**
     * AUDIT-P2-11.1: An unknown format value falls back to JSON (not an error).
     */
    public function test_audit_p211_1_unknown_format_falls_back_to_json(): void
    {
        config(['app.metrics_token' => 'test-token']);

        $response = $this->get('/metrics?token=test-token&format=xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
    }
}
