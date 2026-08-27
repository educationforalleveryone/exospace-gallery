<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Models\OpsIncident;
use App\Ops\Services\IncidentCorrelationService;
use App\Ops\Services\OpsEventIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 2 — incident lifecycle + authorization.
 *
 * Acknowledge / resolve / reopen are the module's FIRST write paths.
 * These tests pin: the auth bar (super-admin + MFA), the audit trail
 * (AdminAuditLog ops.* actions), the state machine, and that the
 * timeline/detail pages render.
 */
class OpsIncidentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.operational_alerts.webhook_url' => null,
            'services.operational_alerts.critical_webhook_url' => null,
        ]);
    }

    private function asMfaSuperAdmin()
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin)->withSession([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    private function createIncident(): OpsIncident
    {
        app(OpsEventIngestor::class)->record([
            'source' => 'app_log', 'severity' => 'critical',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        ]);
        app(IncidentCorrelationService::class)->correlateAll();

        return OpsIncident::firstOrFail();
    }

    public function test_guest_cannot_access_incidents(): void
    {
        $this->get('/ops/incidents')->assertRedirect('/login');
    }

    public function test_regular_user_gets_403(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get('/ops/incidents')->assertStatus(403);
    }

    public function test_super_admin_views_incident_index_and_timeline(): void
    {
        $incident = $this->createIncident();

        $index = $this->asMfaSuperAdmin()->get('/ops/incidents');
        $index->assertStatus(200);
        $index->assertSee($incident->title, false);

        $detail = $this->asMfaSuperAdmin()->get('/ops/incidents/'.$incident->id);
        $detail->assertStatus(200);
        $detail->assertSee('Incident timeline', false);
        $detail->assertSee('Root cause candidate', false);
        $detail->assertSee('ROOT CAUSE CANDIDATE', false);
        $detail->assertSee('Recommended next steps', false);
    }

    public function test_acknowledge_flow_is_audited(): void
    {
        $incident = $this->createIncident();

        $this->asMfaSuperAdmin()
            ->post('/ops/incidents/'.$incident->id.'/acknowledge')
            ->assertRedirect();

        $this->assertSame('acknowledged', $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->acknowledged_at);
        $this->assertSame(
            1,
            AdminAuditLog::where('action', 'ops.incident.acknowledged')->count(),
        );
    }

    public function test_resolve_and_reopen_flow_is_audited(): void
    {
        $incident = $this->createIncident();

        $this->asMfaSuperAdmin()
            ->post('/ops/incidents/'.$incident->id.'/resolve')
            ->assertRedirect();
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->resolved_at);

        $this->asMfaSuperAdmin()
            ->post('/ops/incidents/'.$incident->id.'/reopen')
            ->assertRedirect();
        $this->assertSame('open', $incident->fresh()->status);
        $this->assertNull($incident->fresh()->resolved_at);

        $this->assertSame(1, AdminAuditLog::where('action', 'ops.incident.resolved')->count());
        $this->assertSame(1, AdminAuditLog::where('action', 'ops.incident.reopened')->count());
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $incident = $this->createIncident();

        // Resolve an open incident directly is allowed, then re-resolving
        // is rejected.
        $this->asMfaSuperAdmin()->post('/ops/incidents/'.$incident->id.'/resolve');
        $this->asMfaSuperAdmin()
            ->post('/ops/incidents/'.$incident->id.'/resolve')
            ->assertSessionHasErrors('incident');

        // Acknowledge a resolved incident is rejected.
        $this->asMfaSuperAdmin()
            ->post('/ops/incidents/'.$incident->id.'/acknowledge')
            ->assertSessionHasErrors('incident');
    }

    public function test_actions_require_authentication(): void
    {
        $incident = $this->createIncident();

        $this->post('/ops/incidents/'.$incident->id.'/acknowledge')->assertRedirect('/login');
        $this->assertSame('open', $incident->fresh()->status);
    }

    public function test_overview_page_shows_active_incidents(): void
    {
        $incident = $this->createIncident();

        $response = $this->asMfaSuperAdmin()->get('/ops');

        $response->assertStatus(200);
        $response->assertSee('Active Incidents', false);
        $response->assertSee($incident->title, false);
    }
}
