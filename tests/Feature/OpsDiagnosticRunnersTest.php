<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Ops\Diagnostics\DiagnosticEngine;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsDiagnosticRun;
use App\Ops\Services\OpsEventIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 3 — the diagnostic runners.
 *
 * Each runner is exercised on its HEALTHY state and on at least one broken
 * state (where cheap to simulate), asserting the three things that make a
 * diagnostic operationally useful: a correct status, an actionable
 * interpretation, and findings that say what was actually checked.
 *
 * The test environment runs SQLite + array cache, so driver-specific paths
 * (raw MySQL socket probe, SHOW STATUS) assert their TOLERANT degradation
 * instead — which is itself a contract: a diagnostic must never lie about
 * what it could not measure.
 */
class OpsDiagnosticRunnersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.operational_alerts.webhook_url' => null,
            'services.operational_alerts.critical_webhook_url' => null,
            'services.coolify.api_token' => 'test-token',
            'services.coolify.api_base_url' => 'http://coolify.test',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('logs/scheduler.log'));

        foreach (glob(storage_path('logs/laravel-*.log')) ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function runDiagnostic(string $id, ?OpsApplication $app = null): OpsDiagnosticRun
    {
        $run = app(DiagnosticEngine::class)->run($id, $app);

        $this->assertNotNull($run, "Runner for '{$id}' must produce a run.");

        return $run;
    }

    private function createIngestApp(string $name): OpsApplication
    {
        return OpsApplication::create([
            'slug' => str($name)->slug(),
            'name' => $name,
            'provider' => 'ingest',
            'kind' => 'application',
            'environment' => 'production',
            'health' => 'unknown',
        ]);
    }

    // ── Database ────────────────────────────────────────────────────────

    public function test_database_connectivity_healthy_on_test_driver(): void
    {
        $run = $this->runDiagnostic('database.connectivity');

        $this->assertSame('healthy', $run->status);
        $this->assertStringContainsString('reachable', $run->summary);
        // The socket probe honestly reports it does not apply to this driver.
        $labels = array_column($run->findings, 'label');
        $this->assertTrue(
            (bool) collect($labels)->contains(fn ($l) => str_contains($l, 'Fresh connection probe')),
            'The fresh-connection probe finding must be present (driver-aware).',
        );
    }

    public function test_database_health_healthy_and_flags_events(): void
    {
        // Healthy baseline.
        $run = $this->runDiagnostic('database.health');
        $this->assertSame('healthy', $run->status);

        // With an unresolved DATABASE event, the rollup must surface it.
        app(OpsEventIngestor::class)->record([
            'source' => 'app_log',
            'severity' => 'critical',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        ]);

        $run = $this->runDiagnostic('database.health');
        $this->assertSame('failed', $run->status, 'An open critical DATABASE event must degrade the database health check.');
        $this->assertStringContainsString('query or schema-level', $run->interpretation);
    }

    public function test_database_connection_health_is_inconclusive_on_non_mysql(): void
    {
        $run = $this->runDiagnostic('database.connection-health');

        // Honest capability reporting: pool metrics need MySQL.
        $this->assertSame('inconclusive', $run->status);
        $this->assertStringContainsString('MySQL', $run->interpretation);
    }

    public function test_migration_status_healthy_when_fully_migrated(): void
    {
        $run = $this->runDiagnostic('database.migration-status');

        $this->assertSame('healthy', $run->status);
        $this->assertStringContainsString('up to date', $run->summary);
        $this->assertStringContainsString('none pending', implode(' ', array_column($run->findings, 'detail')));
        $this->assertStringContainsString('fully migrated', $run->interpretation);
    }

    public function test_migration_status_flags_pending_migrations(): void
    {
        // Simulate pending: an on-disk migration the database has not
        // recorded (delete the newest batch row).
        $newest = DB::table('migrations')->orderByDesc('batch')->orderByDesc('id')->first();
        $this->assertNotNull($newest);
        DB::table('migrations')->where('id', $newest->id)->delete();

        $run = $this->runDiagnostic('database.migration-status');

        $this->assertSame('degraded', $run->status);
        $this->assertStringContainsString('pending', $run->summary);
        $this->assertStringContainsString($newest->migration, implode(' ', array_column($run->findings, 'detail')));
        $this->assertStringContainsString('Nothing runs automatically from here', $run->interpretation, 'The interpretation must state that migrations are never auto-run.');
    }

    public function test_migration_status_fails_when_migration_errors_exist(): void
    {
        // A missing-table error — the classifier's first MIGRATION rule
        // (the strict all-needles migration rule needs more context).
        app(OpsEventIngestor::class)->record([
            'source' => 'app_log',
            'severity' => 'critical',
            'message' => 'SQLSTATE[42S02]: Base table or view not found: 1146 Table \'exospace.galleries\' doesn\'t exist',
        ]);

        $run = $this->runDiagnostic('database.migration-status');

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Migration failure suspected', $run->summary);
        $this->assertStringContainsString('never blind-fix', $run->interpretation);
    }

    // ── Redis ───────────────────────────────────────────────────────────

    public function test_redis_connectivity_healthy_with_working_connection(): void
    {
        $this->fakeRedisManager(healthy: true);

        $run = $this->runDiagnostic('redis.connectivity');

        $this->assertSame('healthy', $run->status);
        $this->assertStringContainsString('round-trip', $run->summary);
    }

    public function test_redis_connectivity_classifies_connection_refused(): void
    {
        $this->fakeRedisManager(healthy: false);

        $run = $this->runDiagnostic('redis.connectivity');

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('unreachable', $run->summary);
        $this->assertStringContainsString('platform-level outage', $run->interpretation, 'The interpretation must convey the blast radius (cache+sessions+queue).');
    }

    // ── Queue ───────────────────────────────────────────────────────────

    public function test_queue_health_healthy_when_empty(): void
    {
        $run = $this->runDiagnostic('queue.health');

        $this->assertSame('healthy', $run->status);
    }

    public function test_queue_health_detects_stalled_backlog(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['job' => 'x']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() - 3600, // one hour old
            'created_at' => time() - 3600,
        ]);

        $run = $this->runDiagnostic('queue.health');

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('workers appear down', $run->summary);
        $this->assertStringContainsString('WORKERS are down', $run->interpretation);
    }

    public function test_queue_health_warns_on_failed_job_pile(): void
    {
        for ($i = 0; $i < 15; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => 'test-uuid-'.$i,
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => json_encode(['job' => 'x']),
                'exception' => "App\\Jobs\\SomeJob: production error line\n#0 stack",
                'failed_at' => now()->toDateTimeString(),
            ]);
        }

        $run = $this->runDiagnostic('queue.health');

        $this->assertSame('degraded', $run->status);
    }

    public function test_failed_jobs_diagnostic_groups_failures(): void
    {
        for ($i = 0; $i < 3; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => 'test-uuid-'.$i,
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => json_encode(['job' => 'x']),
                'exception' => "App\\Jobs\\ExportGalleryJob: connection lost mid-export\n#0 frame",
                'failed_at' => now()->subHours(2)->toDateTimeString(),
            ]);
        }

        $run = $this->runDiagnostic('queue.failed-jobs');

        $this->assertNotSame('healthy', $run->status);
        $details = implode(' ', array_column($run->findings, 'detail'));
        $this->assertStringContainsString('3', $details);
        $this->assertStringContainsString('ExportGalleryJob', $details, 'The leading exception line must surface in the findings.');
    }

    // ── Server ──────────────────────────────────────────────────────────

    public function test_server_disk_reports_usage(): void
    {
        $run = $this->runDiagnostic('server.disk');

        $this->assertContains($run->status, ['healthy', 'degraded', 'failed']);
        $this->assertStringContainsString('used', $run->summary);
        $this->assertNotEmpty($run->findings);
    }

    public function test_server_resources_reports_runtime(): void
    {
        $run = $this->runDiagnostic('server.resources');

        $this->assertNotEmpty($run->findings);
        $details = implode(' ', array_column($run->findings, 'detail'));
        $this->assertStringContainsString('PHP', $details);
    }

    // ── Application ─────────────────────────────────────────────────────

    public function test_cache_diagnostic_healthy_on_array_store(): void
    {
        $run = $this->runDiagnostic('app.cache');

        $this->assertSame('healthy', $run->status);
        $this->assertStringContainsString('working', $run->summary);
    }

    public function test_filesystem_diagnostic_healthy(): void
    {
        $run = $this->runDiagnostic('app.filesystem');

        $this->assertSame('healthy', $run->status);
    }

    public function test_scheduler_diagnostic_fails_when_stale(): void
    {
        // scheduler.log exists but ancient → the Coolify scheduled task is dead.
        file_put_contents(storage_path('logs/scheduler.log'), "old output\n");
        touch(storage_path('logs/scheduler.log'), time() - 3600);

        $run = $this->runDiagnostic('app.scheduler');

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('STOPPED', $run->summary);
        $this->assertStringContainsString('Coolify', $run->interpretation, 'The fix lives in Coolify — the interpretation must say so.');
    }

    public function test_scheduler_diagnostic_fails_when_log_missing(): void
    {
        $run = $this->runDiagnostic('app.scheduler');

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('does not exist', implode(' ', array_column($run->findings, 'detail')));
    }

    public function test_scheduler_diagnostic_healthy_when_fresh(): void
    {
        file_put_contents(storage_path('logs/scheduler.log'), "running\n");
        touch(storage_path('logs/scheduler.log'));

        $run = $this->runDiagnostic('app.scheduler');

        $this->assertSame('healthy', $run->status);
    }

    public function test_recent_errors_diagnostic_surfaces_active_problems(): void
    {
        app(OpsEventIngestor::class)->record([
            'source' => 'app_log',
            'severity' => 'critical',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        ]);

        $run = $this->runDiagnostic('app.recent-errors');

        // A critical error within 24h → the finding FAILs → the run fails.
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('critical', $run->summary);
        $this->assertStringContainsString('current problem set', $run->interpretation);
    }

    public function test_recent_errors_diagnostic_for_another_app(): void
    {
        $app = $this->createIngestApp('Project B');
        app(OpsEventIngestor::class)->record([
            'source' => 'ingest',
            'severity' => 'error',
            'message' => 'cURL error 7: Connection refused',
            'application_slug' => $app->slug,
        ]);

        $run = $this->runDiagnostic('app.recent-errors', $app);

        $this->assertNotSame('healthy', $run->status);
        $this->assertStringContainsString('Project B', $run->summary);
    }

    // ── Containers & deployments ────────────────────────────────────────

    public function test_container_health_uses_live_coolify_status(): void
    {
        $app = OpsApplication::create([
            'slug' => 'project-b',
            'name' => 'Project B',
            'provider' => 'coolify',
            'provider_uuid' => 'uuid-b',
            'kind' => 'application',
            'environment' => 'production',
            'status' => 'running:healthy',
            'health' => 'running',
            'status_checked_at' => now(),
        ]);

        Http::fake([
            'http://coolify.test/api/v1/applications' => Http::response([
                ['uuid' => 'uuid-b', 'name' => 'Project B', 'status' => 'exited:1'],
            ]),
            '*' => Http::response([]),
        ]);

        $run = $this->runDiagnostic('container.health', $app);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('NOT running', $run->summary);
        $this->assertStringContainsString('effectively DOWN', $run->interpretation);
    }

    public function test_container_health_inconclusive_without_coolify_uuid(): void
    {
        $app = $this->createIngestApp('Ingest Only App');

        $run = $this->runDiagnostic('container.health', $app);

        $this->assertSame('inconclusive', $run->status);
        $this->assertStringContainsString('No Coolify container', $run->summary);
    }

    public function test_container_recent_logs_for_self_tails_and_redacts_logs(): void
    {
        // A log line carrying a secret-shaped value must NOT survive.
        file_put_contents(
            storage_path('logs/laravel-'.now()->format('Y-m-d').'.log'),
            "[2026-08-26 14:00:00] production.ERROR: boom DB_PASSWORD=supersecretvalue123 oh no\n",
        );
        file_put_contents(storage_path('logs/scheduler.log'), "tick\n");
        touch(storage_path('logs/scheduler.log'));

        $run = $this->runDiagnostic('container.recent-logs');

        $this->assertStringContainsString('fresh', $run->summary);
        $details = implode(' ', array_column($run->findings, 'detail'));
        $this->assertStringContainsString('boom', $details, 'The log tail should include the (redacted) error line.');
        $this->assertStringNotContainsString('supersecretvalue123', $details, 'Secrets in tailed log lines must be redacted.');
    }

    public function test_container_recent_logs_for_other_app_reports_captured_errors(): void
    {
        $app = $this->createIngestApp('Project B');
        app(OpsEventIngestor::class)->record([
            'source' => 'ingest',
            'severity' => 'error',
            'message' => 'cURL error 7: Connection refused',
            'application_slug' => $app->slug,
        ]);

        $run = $this->runDiagnostic('container.recent-logs', $app);

        $this->assertNotSame('healthy', $run->status);
        // Honest capability reporting: no fake raw logs.
        $this->assertStringContainsString('Coolify REST API does not expose container logs', implode(' ', array_column($run->findings, 'detail')));
    }

    public function test_deployment_recent_flags_failed_deployments(): void
    {
        $app = OpsApplication::create([
            'slug' => 'project-b',
            'name' => 'Project B',
            'provider' => 'coolify',
            'provider_uuid' => 'uuid-b',
            'kind' => 'application',
            'environment' => 'production',
            'status' => 'running:healthy',
            'health' => 'running',
        ]);

        Http::fake([
            'http://coolify.test/api/v1/applications/uuid-b/deployments' => Http::response([
                [
                    'deployment_uuid' => 'dep-1',
                    'status' => 'failed',
                    'commit' => 'abcdef1234',
                    'duration' => 120,
                    'created_at' => now()->subHour()->toIso8601String(),
                ],
            ]),
            '*' => Http::response([]),
        ]);

        $run = $this->runDiagnostic('deployment.recent', $app);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('deployment problems', $run->summary);
        $details = implode(' ', array_column($run->findings, 'detail'));
        $this->assertStringContainsString('abcdef1234', $details, 'The failing commit must be visible.');
    }

    public function test_deployment_recent_healthy_when_all_succeeded(): void
    {
        $app = OpsApplication::create([
            'slug' => 'project-c',
            'name' => 'Project C',
            'provider' => 'coolify',
            'provider_uuid' => 'uuid-c',
            'kind' => 'application',
            'environment' => 'production',
            'status' => 'running:healthy',
            'health' => 'running',
        ]);

        Http::fake([
            'http://coolify.test/api/v1/applications/uuid-c/deployments' => Http::response([
                ['deployment_uuid' => 'dep-2', 'status' => 'finished', 'commit' => '123456abcd'],
            ]),
            '*' => Http::response([]),
        ]);

        $run = $this->runDiagnostic('deployment.recent', $app);

        $this->assertSame('healthy', $run->status);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Swap the Redis manager for a stub whose default connection either
     * works or refuses connections — deterministic without a live Redis.
     */
    private function fakeRedisManager(bool $healthy): void
    {
        $connection = new class($healthy)
        {
            public function __construct(private bool $healthy) {}

            public function ping()
            {
                if (! $this->healthy) {
                    // Same message shape Predis surfaces on a refused TCP
                    // connection; the runner classifies by message content.
                    throw new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
                }

                return true;
            }

            public function setex($key, $ttl, $value)
            {
                if (! $this->healthy) {
                    throw new \RuntimeException('nope');
                }

                return true;
            }

            public function get($key)
            {
                return $this->healthy ? 'probe' : null;
            }

            public function del($key)
            {
                return 1;
            }

            public function info($section = null)
            {
                return ['used_memory_human' => '10M', 'maxmemory_human' => '0B'];
            }
        };

        $manager = new class($connection)
        {
            public function __construct(private $connection) {}

            public function connection($name = null)
            {
                return $this->connection;
            }
        };

        $this->app->instance('redis', $manager);
    }
}
