<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ops\Models\OpsEvent;
use App\Ops\Services\OpsEventIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 1 — exception reporting + retention.
 *
 * Exception path: every uncaught exception the framework reports becomes
 * a classified event WITH request context (url, request_id) — while
 * expected 404/validation traffic stays at info severity (a bot hitting
 * random URLs must not paint the platform red).
 *
 * Retention path: auto-resolve stale events, delete old resolved ones,
 * NEVER delete open events.
 */
class OpsExceptionAndRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function ingestor(): OpsEventIngestor
    {
        return app(OpsEventIngestor::class);
    }

    public function test_uncaught_exception_becomes_classified_event_with_request_context(): void
    {
        Route::get('_ops-test/boom', fn () => throw new \RuntimeException(
            'SQLSTATE[HY000] [2002] Connection refused'
        ));

        try {
            $this->get('_ops-test/boom');
        } catch (\RuntimeException) {
            // Without exception handling rendered (APP_DEBUG=false in the
            // test env the handler still reports). Both paths are fine —
            // what matters is the event below.
        }

        // The reportable in bootstrap/app.php recorded it.
        $event = OpsEvent::where('category', 'DATABASE')->first();

        $this->assertNotNull($event, 'Expected the exception to become a DATABASE event.');
        $this->assertSame('critical', $event->severity);
        $this->assertStringContainsString('_ops-test/boom', (string) data_get($event->context, 'http.url', ''));
    }

    public function test_expected_404_traffic_is_recorded_as_info_not_error(): void
    {
        $this->get('/_definitely-not-a-route-xyz');

        $event = OpsEvent::where('category', 'APPLICATION')->first();

        // A 404 must exist as info noise, never as error/critical.
        if ($event !== null) {
            $this->assertSame('info', $event->severity);
        }

        $this->assertSame(0, OpsEvent::whereIn('severity', ['error', 'critical'])->count());
    }

    public function test_prune_auto_resolves_stale_events(): void
    {
        $event = $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error', 'message' => 'A transient glitch happened here',
        ]);
        $event->update(['last_seen_at' => now()->subDays(10)]);

        $this->artisan('ops:prune-events')->assertExitCode(0);

        $this->assertSame('resolved', $event->fresh()->status);
        $this->assertNotNull($event->fresh()->resolved_at);
    }

    public function test_prune_deletes_old_resolved_events_but_never_open_ones(): void
    {
        $resolvedOld = $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error', 'message' => 'Old resolved problem',
        ]);
        $resolvedOld->update([
            'status' => 'resolved',
            'resolved_at' => now()->subDays(200),
            'last_seen_at' => now()->subDays(200),
        ]);

        $openOld = $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error', 'message' => 'Old but still firing problem',
        ]);
        $openOld->update(['last_seen_at' => now()->subDays(200)]); // open, recurring... but stale

        $this->artisan('ops:prune-events')->assertExitCode(0);

        $this->assertNull(OpsEvent::find($resolvedOld->id));   // deleted
        $this->assertNotNull(OpsEvent::find($openOld->id));    // open events survive
        $this->assertSame('resolved', $openOld->fresh()->status); // auto-resolved, kept
    }

    public function test_prune_respects_configured_windows(): void
    {
        config(['ops.retention.auto_resolve_days' => 30]);

        $event = $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error', 'message' => 'Quiet for 10 days',
        ]);
        $event->update(['last_seen_at' => now()->subDays(10)]);

        $this->artisan('ops:prune-events')->assertExitCode(0);

        // 10 days stale with a 30-day window → still open.
        $this->assertSame('open', $event->fresh()->status);
    }
}
