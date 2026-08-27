<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Ops\Diagnostics\DiagnosticEngine;
use App\Ops\Models\OpsDiagnosticRun;
use App\Ops\Models\OpsEvent;
use App\Services\JobHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 4 — the autonomous diagnostic sweep.
 *
 * The sweep turns Iteration 3's pull-only diagnostics into a push watch:
 * probes run on a schedule, degraded/failed findings become DEDUPLICATED
 * control-plane events plus dedup-keyed Slack alerts, recoveries resolve
 * their events, and probes themselves persist NOTHING (no run rows, no
 * audit noise — the machine's routine checks must not bury the human
 * trail). Every guarantee is pinned here.
 */
class OpsSweepDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();

        // Slack target for OperationalAlertService — captured by Http::fake.
        config(['services.operational_alerts.webhook_url' => 'https://slack.test/hook']);

        Http::fake(['slack.test/*' => Http::response(['ok' => true])]);
    }

    protected function tearDown(): void
    {
        // scheduler.log belongs to the real deployment, not the test run.
        @unlink(storage_path('logs/scheduler.log'));

        parent::tearDown();
    }

    private function stampAllHeartbeats(): void
    {
        $heartbeats = app(JobHeartbeatService::class);
        foreach (array_keys(JobHeartbeatService::MONITORED_JOBS) as $job) {
            $heartbeats->stamp($job);
        }
    }

    private function freshSchedulerLog(): void
    {
        file_put_contents(storage_path('logs/scheduler.log'), 'sweep-test');
        touch(storage_path('logs/scheduler.log'), time());
    }

    private function runSweep(): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('ops:sweep-diagnostics');
    }

    private function sweepEvents()
    {
        return OpsEvent::where('source', 'sweep');
    }

    // ── Contract basics ──────────────────────────────────────────────────

    public function test_kill_switch_makes_the_sweep_a_noop(): void
    {
        config([
            'ops.sweeps.enabled' => false,
            'ops.sweeps.diagnostics' => ['queue.health'],
        ]);

        $this->runSweep()->expectsOutputToContain('disabled')->assertExitCode(0);

        $this->assertSame(0, $this->sweepEvents()->count());
        Http::assertNothingSent();
    }

    public function test_unknown_diagnostic_ids_are_skipped_not_fatal(): void
    {
        config([
            'ops.sweeps.diagnostics' => ['totally.bogus', 'database.connectivity'],
        ]);

        $this->runSweep()
            ->expectsOutputToContain("not in the allow-list")
            ->assertExitCode(0);

        $this->assertSame(0, $this->sweepEvents()->count());
    }

    public function test_application_scoped_diagnostics_cannot_be_swept(): void
    {
        config([
            'ops.sweeps.diagnostics' => ['container.health'],
        ]);

        $this->runSweep()
            ->expectsOutputToContain('self-scoped')
            ->assertExitCode(0);

        $this->assertSame(0, $this->sweepEvents()->count());
    }

    public function test_probe_enforces_the_allow_list(): void
    {
        $this->assertNull(app(DiagnosticEngine::class)->probe('no.such.diagnostic'));
    }

    public function test_probe_persists_nothing_and_audits_nothing(): void
    {
        $result = app(DiagnosticEngine::class)->probe('database.connectivity');

        $this->assertNotNull($result);
        $this->assertSame('healthy', $result->status); // sqlite in tests
        $this->assertSame(0, OpsDiagnosticRun::count(), 'Probes must not create ops_diagnostic_runs rows');
        $this->assertSame(
            0,
            AdminAuditLog::query()->where('action', 'ops.diagnostic.run')->count(),
            'Probes must not write audit entries — they are machine routine, not operator action',
        );
    }

    // ── The healthy path: silence ────────────────────────────────────────

    public function test_all_healthy_sweep_records_no_events_alerts_nothing_and_exits_clean(): void
    {
        $this->stampAllHeartbeats();
        $this->freshSchedulerLog();

        config([
            'ops.sweeps.diagnostics' => ['queue.health', 'app.scheduler', 'database.connectivity'],
        ]);

        $this->runSweep()->assertExitCode(0);

        $this->assertSame(0, $this->sweepEvents()->count());
        $this->assertSame(0, OpsDiagnosticRun::count());
        Http::assertNothingSent();
    }

    // ── Degraded findings → warning events + alerts ─────────────────────

    public function test_degraded_check_records_warning_event_and_alerts_slack(): void
    {
        $this->stampAllHeartbeats();

        // >10 failed jobs = the warning threshold queue.health mirrors.
        $now = now()->getTimestamp();
        $rows = [];
        for ($i = 0; $i < 15; $i++) {
            $rows[] = [
                'uuid' => 'swp-'.$i, 'connection' => 'redis', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'RuntimeException: boom', 'failed_at' => now(),
            ];
        }
        \DB::table('failed_jobs')->insert($rows);

        config(['ops.sweeps.diagnostics' => ['queue.health']]);

        $this->runSweep()->assertExitCode(0);

        $event = $this->sweepEvents()->first();
        $this->assertNotNull($event, 'A degraded finding must become a control-plane event');
        $this->assertSame('QUEUE', $event->category);
        $this->assertSame('warning', $event->severity);
        $this->assertSame('Automated sweep: Queue & worker health', $event->title);
        $this->assertSame('open', $event->status);
        $this->assertSame(1, $event->occurrence_count);
        $this->assertTrue((bool) data_get($event->context, 'sweep'));
        $this->assertSame('queue.health', data_get($event->context, 'diagnostic'));
        $this->assertNotEmpty(data_get($event->context, 'findings'));

        // Slack got exactly one warning with the dedup key semantics.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'slack.test')
                && str_contains((string) $request->body(), 'degraded');
        });
        Http::assertSentCount(1);
    }

    public function test_failed_check_records_error_event(): void
    {
        $this->stampAllHeartbeats();

        // No scheduler.log at all → the app.scheduler check FAILS.
        @unlink(storage_path('logs/scheduler.log'));

        config(['ops.sweeps.diagnostics' => ['app.scheduler']]);

        $this->runSweep()->assertExitCode(0);

        $event = $this->sweepEvents()->first();
        $this->assertNotNull($event);
        $this->assertSame('error', $event->severity, 'A FAILED finding is an error-level event');
        $this->assertSame('INFRASTRUCTURE', $event->category);
        $this->assertSame('Automated sweep: Scheduler & scheduled jobs', $event->title);
    }

    public function test_redis_refusal_becomes_a_classified_redis_failure_event(): void
    {
        // Point the default Redis connection at a port nothing listens on —
        // the fresh-connection probe gets refused, the runner CLASSIFIES
        // the failure mode (refused), and the sweep surfaces it as an
        // error event in the REDIS category. This is exactly the
        // "Redis is down" scenario from the brief, end to end.
        config([
            'database.redis.client' => 'predis',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 59999,
            'ops.sweeps.diagnostics' => ['redis.connectivity'],
        ]);

        $this->runSweep()->assertExitCode(0);

        $event = $this->sweepEvents()->first();
        $this->assertNotNull($event, 'A refused Redis connection must become a sweep event');
        $this->assertSame('REDIS', $event->category);
        $this->assertSame('error', $event->severity);
        $this->assertSame('Automated sweep: Redis connectivity & latency', $event->title);

        Http::assertSent(function ($request) {
            return str_contains((string) $request->body(), 'Redis connectivity');
        });
    }

    // ── Dedup + recovery — the anti-noise contract ──────────────────────

    public function test_recurrence_bumps_the_counter_and_suppresses_the_duplicate_alert(): void
    {
        $this->stampAllHeartbeats();

        $rows = [];
        for ($i = 0; $i < 15; $i++) {
            $rows[] = [
                'uuid' => 'dup-'.$i, 'connection' => 'redis', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'RuntimeException: boom', 'failed_at' => now(),
            ];
        }
        \DB::table('failed_jobs')->insert($rows);

        config(['ops.sweeps.diagnostics' => ['queue.health']]);

        $this->runSweep()->assertExitCode(0);
        $this->runSweep()->assertExitCode(0);

        // One deduplicated row, occurrence bumped to 2.
        $this->assertSame(1, $this->sweepEvents()->count());
        $this->assertSame(2, (int) $this->sweepEvents()->first()->occurrence_count);

        // One Slack alert — the second was suppressed by the dedup key TTL.
        Http::assertSentCount(1);
    }

    public function test_recovery_resolves_the_event_and_announces_it(): void
    {
        $this->stampAllHeartbeats();

        $rows = [];
        for ($i = 0; $i < 15; $i++) {
            $rows[] = [
                'uuid' => 'rec-'.$i, 'connection' => 'redis', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'RuntimeException: boom', 'failed_at' => now(),
            ];
        }
        \DB::table('failed_jobs')->insert($rows);

        config(['ops.sweeps.diagnostics' => ['queue.health']]);

        // Sweep 1: problem found.
        $this->runSweep()->assertExitCode(0);
        $event = $this->sweepEvents()->first();
        $this->assertNotNull($event);
        $this->assertSame('open', $event->status);

        // Fix the problem.
        \DB::table('failed_jobs')->delete();

        // Sweep 2: healthy again → the event resolves, Slack gets the info note.
        $this->runSweep()->assertExitCode(0);

        $event->refresh();
        $this->assertSame('resolved', $event->status);
        $this->assertNotNull($event->resolved_at);

        Http::assertSent(function ($request) {
            return str_contains((string) $request->body(), 'recovered');
        });
    }

    public function test_recovery_falls_back_to_title_lookup_when_cache_is_flushed(): void
    {
        $this->stampAllHeartbeats();

        $rows = [];
        for ($i = 0; $i < 15; $i++) {
            $rows[] = [
                'uuid' => 'fb-'.$i, 'connection' => 'redis', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'RuntimeException: boom', 'failed_at' => now(),
            ];
        }
        \DB::table('failed_jobs')->insert($rows);

        config(['ops.sweeps.diagnostics' => ['queue.health']]);

        $this->runSweep()->assertExitCode(0);
        $this->assertSame(1, $this->sweepEvents()->count());

        // The cached event id evaporates (deploy, Redis restart) — the
        // sweep must still find and resolve the open event by its title.
        \Illuminate\Support\Facades\Cache::flush();
        \DB::table('failed_jobs')->delete();

        $this->runSweep()->assertExitCode(0);

        $event = $this->sweepEvents()->first();
        $this->assertSame('resolved', $event->status, 'Title fallback must resolve the event after a cache flush');
    }

    // ── Never fatal ──────────────────────────────────────────────────────

    public function test_sweep_output_summarizes_and_always_exits_zero(): void
    {
        $this->stampAllHeartbeats();
        $this->freshSchedulerLog();

        config([
            'ops.sweeps.diagnostics' => [
                'database.connectivity',
                'queue.health',
                'app.scheduler',
                'server.disk',
                'unknown.diagnostic',
            ],
        ]);

        $this->runSweep()
            ->expectsOutputToContain('Sweep complete')
            ->assertExitCode(0);
    }
}
