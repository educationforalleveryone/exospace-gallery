<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsDiagnosticRun;
use App\Ops\Models\OpsEvent;
use App\Ops\Services\OpsEventIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 3 — the diagnostics UI.
 *
 * Pins the surfaces the operator actually touches: the catalog page, the
 * run-result page, one-click buttons on error/incident pages (the whole
 * point of the iteration), and the per-application quick actions.
 */
class OpsDiagnosticsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.operational_alerts.webhook_url' => null,
            'services.operational_alerts.critical_webhook_url' => null,
            'ops.actions.enabled' => true,
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

    public function test_diagnostics_catalog_renders_all_groups_and_run_buttons(): void
    {
        $response = $this->asMfaSuperAdmin()->get('/ops/diagnostics');

        $response->assertOk()
            ->assertSee('Database', false)
            ->assertSee('Cache &amp; Queue', false)
            ->assertSee('Containers &amp; Deployments', false)
            ->assertSee('Server', false)
            ->assertSee('Application', false)
            ->assertSee('Migration status', false)
            ->assertSee('Redis connectivity', false)
            ->assertSee('Queue &amp; worker health', false);

        // Every registered diagnostic renders a run button with its id.
        foreach (\App\Ops\Diagnostics\DiagnosticRegistry::all() as $id => $definition) {
            $response->assertSee('name="diagnostic" value="'.$id.'"', false);
        }
    }

    public function test_catalog_supports_application_filter(): void
    {
        $app = OpsApplication::create([
            'slug' => 'project-b',
            'name' => 'Project B',
            'provider' => 'coolify',
            'provider_uuid' => 'uuid-b',
            'kind' => 'application',
            'environment' => 'production',
        ]);

        $this->asMfaSuperAdmin()
            ->get('/ops/diagnostics?app='.$app->id)
            ->assertOk()
            ->assertSee('Project B', false)
            ->assertSee('Target: Project B', false);
    }

    public function test_run_result_page_renders_findings_interpretation_and_next_steps(): void
    {
        $run = app(\App\Ops\Diagnostics\DiagnosticEngine::class)->run('queue.health');

        $this->asMfaSuperAdmin()
            ->get(route('ops.diagnostics.show', $run))
            ->assertOk()
            ->assertSee('What was checked', false)
            ->assertSee('What this means', false)
            ->assertSee('Queue &amp; worker health', false)
            ->assertSee('Run again', false);
    }

    public function test_event_detail_page_shows_runnable_diagnostic_buttons(): void
    {
        $event = app(OpsEventIngestor::class)->record([
            'source' => 'app_log',
            'severity' => 'critical',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        ]);

        $this->asMfaSuperAdmin()
            ->get(route('ops.events.show', $event))
            ->assertOk()
            ->assertSee('Run a check with one click', false)
            // The classifier recommends these for DB connection failures:
            ->assertSee('name="diagnostic" value="database.connectivity"', false)
            ->assertSee('name="diagnostic" value="database.health"', false)
            ->assertSee('name="event" value="'.$event->id.'"', false)
            // The stale "coming in Iteration 3" copy is gone:
            ->assertDontSee('arrive in Iteration 3');
    }

    public function test_applications_page_shows_quick_actions(): void
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

        $this->asMfaSuperAdmin()
            ->get(route('ops.applications'))
            ->assertOk()
            ->assertSee('Run checks', false)
            ->assertSee('Restart…', false)
            ->assertSee('Sync now', false);
    }

    public function test_navigation_links_diagnostics_and_actions(): void
    {
        $this->asMfaSuperAdmin()
            ->get('/ops/diagnostics')
            ->assertOk()
            ->assertSee('>Diagnostics</a>', false)
            ->assertSee('>Actions</a>', false);
    }

    public function test_recent_runs_table_lists_persisted_runs(): void
    {
        app(\App\Ops\Diagnostics\DiagnosticEngine::class)->run('server.disk');

        $this->asMfaSuperAdmin()
            ->get('/ops/diagnostics')
            ->assertOk()
            ->assertSee('Recent runs', false)
            ->assertSee('Disk usage', false);
    }
}
