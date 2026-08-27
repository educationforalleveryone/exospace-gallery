<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Diagnostics\DiagnosticEngine;
use App\Ops\Diagnostics\DiagnosticResult;
use App\Ops\Diagnostics\Runners\DatabaseDiagnostics;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsDiagnosticRun;
use App\Ops\Models\OpsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 3 — the diagnostic engine.
 *
 * Pins the engine's security and robustness contracts:
 *   - the allow-list (unknown id → null / 404, no capability oracle),
 *   - the auth bar (guest / regular user / super-admin without MFA),
 *   - audit + persistence for every run,
 *   - redaction of findings before persistence,
 *   - the honest scope guard (self-scoped check vs another application),
 *   - provenance (event/incident sources),
 *   - the never-throw contract for exploding runners.
 */
class OpsDiagnosticEngineTest extends TestCase
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

    // ── Auth bar ────────────────────────────────────────────────────────

    public function test_guest_cannot_run_diagnostics(): void
    {
        $this->post('/ops/diagnostics/run', ['diagnostic' => 'database.connectivity'])
            ->assertRedirect('/login');
    }

    public function test_regular_user_gets_403(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->post('/ops/diagnostics/run', ['diagnostic' => 'database.connectivity'])
            ->assertStatus(403);
    }

    public function test_super_admin_without_mfa_is_rejected(): void
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);

        // Logged in but WITHOUT the mfa_verified session flag → the MFA
        // middleware bounces to the verification challenge.
        $this->actingAs($admin)
            ->post('/ops/diagnostics/run', ['diagnostic' => 'database.connectivity'])
            ->assertRedirect(route('mfa.verify'));
    }

    // ── Allow-list ──────────────────────────────────────────────────────

    public function test_unknown_diagnostic_id_is_rejected_with_404(): void
    {
        $this->asMfaSuperAdmin()
            ->post('/ops/diagnostics/run', ['diagnostic' => 'shell.exec']) // a hostile "diagnostic"
            ->assertStatus(404);

        $this->assertSame(0, OpsDiagnosticRun::count(), 'A rejected diagnostic must not be persisted.');
        $this->assertSame(0, AdminAuditLog::where('action', 'ops.diagnostic.run')->count());
    }

    public function test_engine_returns_null_for_unknown_id(): void
    {
        $engine = app(DiagnosticEngine::class);

        $this->assertNull($engine->run('totally.bogus'));
    }

    // ── Happy path: run + persist + audit ───────────────────────────────

    public function test_run_persists_result_and_audits(): void
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);

        // AdminAuditLog::record captures the actor from the Auth facade —
        // authenticate so the audit row carries the acting user even in a
        // direct service-level call (HTTP requests do this automatically).
        $this->actingAs($admin);

        $run = app(DiagnosticEngine::class)->run('database.connectivity', null, $admin, 'manual');

        $this->assertNotNull($run);
        $this->assertSame('database.connectivity', $run->diagnostic_id);
        $this->assertSame($admin->id, $run->actor_id);
        $this->assertContains($run->status, OpsDiagnosticRun::STATUSES);
        $this->assertNotEmpty($run->summary);
        $this->assertNotEmpty($run->findings);
        $this->assertNotEmpty($run->interpretation);
        $this->assertGreaterThanOrEqual(0, $run->duration_ms);

        $audit = AdminAuditLog::where('action', 'ops.diagnostic.run')->first();
        $this->assertNotNull($audit, 'Every diagnostic run must be audited.');
        $this->assertSame('database.connectivity', $audit->payload['diagnostic']);
        $this->assertSame($admin->id, $audit->actor_id);
    }

    public function test_run_via_http_redirects_to_result_page(): void
    {
        $response = $this->asMfaSuperAdmin()
            ->post('/ops/diagnostics/run', ['diagnostic' => 'queue.health']);

        $run = OpsDiagnosticRun::first();

        $response->assertRedirect(route('ops.diagnostics.show', $run));
        $response->assertSessionHas('success');

        // The result page renders the run.
        $this->get(route('ops.diagnostics.show', $run))
            ->assertOk()
            ->assertSee('Queue &amp; worker health', false)
            ->assertSee($run->summary, false);
    }

    // ── Provenance ──────────────────────────────────────────────────────

    public function test_run_from_an_event_records_provenance_and_target(): void
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);

        $event = OpsEvent::create([
            'fingerprint' => 'test-'.uniqid(),
            'ops_application_id' => null,
            'source' => 'app_log',
            'category' => 'QUEUE',
            'severity' => 'error',
            'title' => 'Queue job failed after retries',
            'message' => 'MaxAttemptsExceededException',
            'occurrence_count' => 1,
            'total_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'status' => 'open',
            'environment' => 'testing',
        ]);

        $run = app(DiagnosticEngine::class)->run(
            'queue.health',
            $event->application,
            $admin,
            'event',
            $event->id,
        );

        $this->assertSame('event', $run->source);
        $this->assertSame($event->id, $run->source_id);
        $this->assertSame('from event #'.$event->id, $run->sourceLabel());
    }

    public function test_http_run_with_bogus_event_id_404s(): void
    {
        $this->asMfaSuperAdmin()
            ->post('/ops/diagnostics/run', ['diagnostic' => 'queue.health', 'event' => 999999])
            ->assertStatus(404);
    }

    // ── Scope guard ─────────────────────────────────────────────────────

    public function test_self_scoped_diagnostic_against_another_app_is_honestly_inconclusive(): void
    {
        $other = OpsApplication::create([
            'slug' => 'project-b',
            'name' => 'Project B',
            'provider' => 'ingest',
            'kind' => 'application',
            'environment' => 'production',
            'health' => 'unknown',
        ]);

        $run = app(DiagnosticEngine::class)->run('database.connectivity', $other);

        $this->assertSame('inconclusive', $run->status, 'A self-scoped check aimed at another app must never silently check the wrong thing.');
        $this->assertStringContainsString('Project B', $run->summary);
        $this->assertStringContainsString('Container health', $run->interpretation, 'The honest answer must point at the diagnostics that CAN help.');
    }

    // ── Redaction ───────────────────────────────────────────────────────

    public function test_findings_are_redacted_before_persistence(): void
    {
        // Bind a fake runner that returns findings containing secret-shaped
        // strings — the engine must redact them before storing (defense in
        // depth; the redactor's own patterns are pinned by its unit tests).
        $this->app->instance(DatabaseDiagnostics::class, new class implements \App\Ops\Diagnostics\RunsDiagnostics
        {
            public function runDiagnostic(string $id, ?OpsApplication $application): DiagnosticResult
            {
                return DiagnosticResult::fromFindings(
                    'Probe with password=hunter2 leaking',
                    [[
                        'label' => 'Leaky check',
                        'status' => 'pass',
                        'detail' => 'DB_PASSWORD=supersecretvalue123 should never survive persistence',
                    ]],
                    'Interpretation mentioning redis_password=abcdefabcdefabcdefabcdef should not survive either',
                );
            }
        });

        $run = app(DiagnosticEngine::class)->run('database.connectivity');

        $stored = json_encode($run->getAttributes());

        $this->assertStringNotContainsString('supersecretvalue123', $stored);
        $this->assertStringNotContainsString('hunter2', $stored);
        $this->assertStringNotContainsString('abcdefabcdefabcdefabcdef', $stored);
    }

    // ── Never-throw ─────────────────────────────────────────────────────

    public function test_exploding_runner_becomes_a_failed_run_not_a_500(): void
    {
        $this->app->instance(DatabaseDiagnostics::class, new class implements \App\Ops\Diagnostics\RunsDiagnostics
        {
            public function runDiagnostic(string $id, ?OpsApplication $application): DiagnosticResult
            {
                throw new \RuntimeException('kaboom — runner exploded');
            }
        });

        $run = app(DiagnosticEngine::class)->run('database.connectivity');

        $this->assertNotNull($run, 'An exploding runner must still produce a persisted run.');
        $this->assertSame('inconclusive', $run->status);
        $this->assertStringContainsString('kaboom', $run->interpretation);

        // And over HTTP it still does not 500:
        $this->asMfaSuperAdmin()
            ->post('/ops/diagnostics/run', ['diagnostic' => 'database.connectivity'])
            ->assertRedirect();
    }
}
