<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Ops\Models\OpsIncident;
use App\Ops\Services\IncidentCorrelationService;
use App\Ops\Services\OpsEventIngestor;
use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 2 — the correlation engine.
 *
 * The brief's core requirement, asserted end-to-end:
 *
 *     Deployment #184 → Migration failed → Container restarted
 *     → HTTP 500 increased → Sentry spike
 *
 *     must become ONE incident with a timeline and a root-cause candidate
 *     — not five unrelated problems.
 */
class OpsIncidentCorrelationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Keep alerting side-effects deterministic: capture alert calls
        // instead of letting Slack webhooks fire (they would in prod).
        config([
            'services.operational_alerts.webhook_url' => null,
            'services.operational_alerts.critical_webhook_url' => null,
        ]);
    }

    private function ingestor(): OpsEventIngestor
    {
        return app(OpsEventIngestor::class);
    }

    private function service(): IncidentCorrelationService
    {
        return app(IncidentCorrelationService::class);
    }

    /**
     * THE flagship scenario from the brief.
     */
    public function test_deployment_cascade_becomes_one_incident(): void
    {
        // 15:02 deployment failed (Coolify sync)
        $deployment = $this->ingestor()->record([
            'source' => 'coolify', 'category' => 'DEPLOYMENT', 'severity' => 'critical',
            'title' => 'Deployment failed — Exospace',
            'message' => 'Coolify deployment finished with status "failed".',
            'context' => ['deployment_uuid' => 'dep-184', 'commit' => 'abc1234'],
        ]);
        // 15:04 migration failed
        $migration = $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'critical',
            'message' => 'SQLSTATE[42S22]: Unknown column in migration xyz',
        ]);
        // 15:05 DB connection errors follow
        $db1 = $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'critical',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        ]);
        $db2 = $this->ingestor()->record([
            'source' => 'exception', 'severity' => 'error',
            'message' => 'Another application exception occurred in billing flow',
        ]);

        $this->service()->correlateAll();

        // ONE incident — not four.
        $this->assertSame(1, OpsIncident::count());

        $incident = OpsIncident::first();
        $this->assertSame('Deployment failure cascade — Exospace', $incident->title);
        $this->assertSame('DEPLOYMENT', $incident->root_cause_category);
        $this->assertSame('high', $incident->confidence);
        $this->assertSame('critical', $incident->severity);

        // All four events are linked to the incident (timeline members).
        foreach ([$deployment, $migration, $db1, $db2] as $event) {
            $this->assertSame($incident->id, $event->fresh()->ops_incident_id);
        }

        // Root cause is the deployment event.
        $this->assertSame($deployment->id, $incident->root_cause_event_id);

        // The timeline renders chronologically with the root first.
        $timeline = $incident->timeline()->get();
        $this->assertSame($deployment->id, $timeline->first()->id);
    }

    public function test_events_outside_window_do_not_merge(): void
    {
        $old = $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error', 'message' => 'Redis connection refused happened here',
        ]);
        // Push the event outside the 30-minute adoption window.
        $old->update(['first_seen_at' => now()->subHours(3), 'last_seen_at' => now()->subHours(3)]);

        $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error', 'message' => 'A different problem entirely occurred',
        ]);

        $this->service()->correlateAll();

        // Two separate incidents — time separated.
        $this->assertSame(2, OpsIncident::count());
    }

    public function test_cluster_without_causal_header_is_medium_confidence(): void
    {
        $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error',
            'message' => 'Redis went away causing cache errors',
        ]);
        $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error',
            'message' => 'Queue jobs failing due to another redis issue',
        ]);

        $this->service()->correlateAll();

        $incident = OpsIncident::first();
        $this->assertSame('medium', $incident->confidence);
        $this->assertSame(2, $incident->event_count);
    }

    public function test_single_event_is_low_confidence_solo_incident(): void
    {
        $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error',
            'message' => 'One isolated glitch happened once',
        ]);

        $this->service()->correlateAll();

        $incident = OpsIncident::first();
        $this->assertSame('low', $incident->confidence);
        $this->assertSame(1, $incident->event_count);
    }

    public function test_correlation_is_idempotent(): void
    {
        $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error', 'message' => 'Recurring unique problem signature A',
        ]);

        $this->service()->correlateAll();
        $this->service()->correlateAll();

        $this->assertSame(1, OpsIncident::count());
    }

    public function test_severity_escalates_but_never_deescalates(): void
    {
        $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error', 'message' => 'Initial warning-level problem here',
        ]);
        $this->service()->correlateAll();

        $incident = OpsIncident::first();
        $this->assertSame('error', $incident->severity);

        // A critical event joins the story.
        $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'critical',
            'message' => 'Now the database is completely unreachable',
        ]);
        $this->service()->correlateAll();

        $incident = OpsIncident::first();
        $this->assertSame('critical', $incident->severity);
    }

    public function test_resolved_incident_reopens_on_recurrence(): void
    {
        $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error', 'message' => 'A problem that comes back later',
        ]);
        $this->service()->correlateAll();

        $incident = OpsIncident::first();
        $incident->update(['status' => 'resolved', 'resolved_at' => now()]);

        // Same fingerprint recurs → the event links and the incident reopens.
        $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error', 'message' => 'A problem that comes back later',
        ]);
        $this->service()->correlateAll();

        $incident = OpsIncident::first();
        $this->assertSame('open', $incident->status);
        $this->assertSame(1, OpsIncident::count()); // still ONE story
    }

    public function test_incident_creation_alerts_and_audits(): void
    {
        $alerts = 0;
        $this->mock(OperationalAlertService::class, function ($mock) use (&$alerts) {
            $mock->shouldReceive('alert')->andReturnUsing(function () use (&$alerts) {
                $alerts++;
            });
        });
        $this->instance(OperationalAlertService::class, app(OperationalAlertService::class));
        // Rebind the correlation service so it receives the mocked alerter.
        $this->app->forgetInstance(IncidentCorrelationService::class);

        $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'critical',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        ]);

        app(IncidentCorrelationService::class)->correlateAll();

        $this->assertSame(1, $alerts);
        $this->assertSame(1, AdminAuditLog::where('action', 'ops.incident.created')->count());
    }

    public function test_different_applications_never_share_incidents(): void
    {
        $appB = $this->ingestor()->resolveOrCreateApplication('project-b', 'Project B');

        $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error',
            'message' => 'Same message text but different app must not correlate',
        ]);
        $this->ingestor()->record([
            'source' => 'ingest', 'severity' => 'error',
            'title' => 'Same message text but different app must not correlate',
            'message' => 'Same message text but different app must not correlate',
            'application_id' => $appB->id,
        ]);

        $this->service()->correlateAll();

        $this->assertSame(2, OpsIncident::count());
        $this->assertSame(
            1,
            OpsIncident::where('ops_application_id', $appB->id)->count(),
        );
    }

    public function test_scheduled_command_runs_the_sweep(): void
    {
        $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error', 'message' => 'Sweep target problem for command test',
        ]);

        $this->artisan('ops:correlate-incidents')->assertExitCode(0);

        $this->assertSame(1, OpsIncident::count());
    }
}
