<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JobHeartbeatService;
use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ITERATION 6 — per-job heartbeat alerting.
 *
 * checkSchedulerHealth() only catches a dead scheduler LOOP. These tests
 * pin the layer that catches an individual job silently stopping while
 * the scheduler is healthy:
 *   1. stamp() → 'fresh'; expired stamp → 'stale'; never stamped → 'missing'
 *   2. A stale heartbeat pages (critical) via the operational channel
 *   3. A fresh heartbeat never pages
 *   4. 'missing' pages only AFTER the first-observation ack ages past the
 *      job's max age (fresh installs must not page on day one)
 *   5. Monitored commands stamp on completion — including clean no-ops
 *      (reconcile unconfigured = feature OFF, not job DEAD)
 */
class JobHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['services.operational_alerts.webhook_url' => 'https://hooks.example.test/services/T000/B000/hb']);
    }

    public function test_stamp_makes_job_fresh_and_exposure_of_last_run(): void
    {
        $heartbeats = app(JobHeartbeatService::class);

        $this->assertSame('missing', $heartbeats->status('exospace:cleanup-stale'));

        $heartbeats->stamp('exospace:cleanup-stale');

        $this->assertSame('fresh', $heartbeats->status('exospace:cleanup-stale'));
        $this->assertNotNull($heartbeats->lastRunAt('exospace:cleanup-stale'));
    }

    public function test_expired_stamp_reports_stale(): void
    {
        $heartbeats = app(JobHeartbeatService::class);

        // reconcile: daily, max age 36h — stamp 40h ago.
        Cache::put(
            'heartbeat:job:exospace:reconcile-subscriptions',
            now()->subHours(40)->toIso8601String(),
            now()->addHours(48),
        );

        $this->assertSame('stale', $heartbeats->status('exospace:reconcile-subscriptions'));
    }

    public function test_stale_heartbeat_pages_critical(): void
    {
        Cache::put(
            'heartbeat:job:exospace:reconcile-subscriptions',
            now()->subHours(40)->toIso8601String(),
            now()->addHours(48),
        );

        app(OperationalAlertService::class)->checkJobHeartbeats();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.test/services/T000/B000/hb'
                && str_contains($request->body(), 'Scheduled job missed its cadence')
                && str_contains($request->body(), 'exospace:reconcile-subscriptions')
                && str_contains($request->body(), 'safety net for missed 2Checkout webhooks');
        });
    }

    public function test_fresh_heartbeats_never_page(): void
    {
        foreach (JobHeartbeatService::MONITORED_JOBS as $job => $maxAge) {
            app(JobHeartbeatService::class)->stamp($job);
        }

        app(OperationalAlertService::class)->checkJobHeartbeats();

        Http::assertNothingSent();
    }

    public function test_missing_heartbeat_acks_first_and_pages_only_after_grace(): void
    {
        $heartbeats = app(JobHeartbeatService::class);

        // First observation of a never-run job: ack only, no alert.
        app(OperationalAlertService::class)->checkJobHeartbeats();
        Http::assertNothingSent();

        $this->assertNotNull(
            $heartbeats->firstObservedMissingAt('exospace:cleanup-stale'),
            'the first observation records the grace clock',
        );

        // Age the ack past the job's max age (36h for cleanup-stale).
        Cache::put(
            'heartbeat:job:exospace:cleanup-stale:missing_since',
            now()->subHours(40)->toIso8601String(),
            now()->addDays(30),
        );

        app(OperationalAlertService::class)->checkJobHeartbeats();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.test/services/T000/B000/hb'
                && str_contains($request->body(), 'Scheduled job has never completed')
                && str_contains($request->body(), 'exospace:cleanup-stale');
        });
    }

    public function test_stamp_clears_pending_missing_ack(): void
    {
        $heartbeats = app(JobHeartbeatService::class);

        // Ack a missing job, then the job runs before the grace expires.
        $heartbeats->ackMissing('sitemap:warm');
        $heartbeats->stamp('sitemap:warm');

        $this->assertNull(
            $heartbeats->firstObservedMissingAt('sitemap:warm'),
            'a fresh stamp invalidates any pending never-ran ack',
        );
        $this->assertSame('fresh', $heartbeats->status('sitemap:warm'));
    }

    public function test_reconcile_noop_run_stamps_its_heartbeat(): void
    {
        // 2CO unconfigured (local/CI shape) — the job completes as a no-op.
        config(['services.2checkout.account_number' => null, 'services.2checkout.secret_word' => null]);

        $this->artisan('exospace:reconcile-subscriptions')->assertExitCode(0);

        $this->assertSame(
            'fresh',
            app(JobHeartbeatService::class)->status('exospace:reconcile-subscriptions'),
            'a completed no-op still proves the scheduler ran the job',
        );
    }

    public function test_cleanup_stale_run_stamps_its_heartbeat(): void
    {
        $this->artisan('exospace:cleanup-stale')->assertExitCode(0);

        $this->assertSame('fresh', app(JobHeartbeatService::class)->status('exospace:cleanup-stale'));
    }
}
