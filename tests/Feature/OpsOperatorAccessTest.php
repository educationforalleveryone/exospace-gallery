<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Models\OpsAccessGrant;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use App\Ops\Support\OpsAccessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 6 — the operator tier.
 *
 * Iteration 5's level column reserved the design space; this iteration
 * fills it: an 'operator' grant is "viewer + the right to RUN the
 * read-only diagnostics" and NOTHING else. These tests pin:
 *
 *   1. The gate: operators read everything viewers read (unchanged bar:
 *      verified + MFA + active grant).
 *   2. The ROUTE-LEVEL split: operators can POST /ops/diagnostics/run
 *      (the one surface the tier exists for) and NOTHING else — the
 *      Actions hub, credentials, access management and incident
 *      lifecycle 403 at the route level, direct URL or not.
 *   3. Tier independence: each kill switch fail-closes only its own
 *      tier; the other tier and super-admins are untouched.
 *   4. Level changes: viewer → operator → viewer is atomic (revoke +
 *      re-grant, both ledger rows, both audited, one Slack note).
 *   5. UI honesty: operators see RUN buttons + the OPERATOR badge, but
 *      no infrastructure buttons, nav doors or lifecycle controls;
 *      viewers see exactly what they saw in Iteration 5.
 *   6. OpsAccessContext: the view-layer resolver mirrors the middleware
 *      exactly (fail-closed, per-request cached, flushable).
 */
class OpsOperatorAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.operational_alerts.webhook_url' => null,
            'services.operational_alerts.critical_webhook_url' => null,
            'ops.access.viewer_enabled' => true,
            'ops.access.operator_enabled' => true,
        ]);

        OpsAccessContext::flush();
    }

    protected function tearDown(): void
    {
        OpsAccessContext::flush();

        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

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

    /**
     * A regular, verified, MFA-enabled user holding an active grant at
     * the given level, already MFA-verified in-session.
     */
    private function asGrantee(string $level, array $userOverrides = [])
    {
        $user = User::factory()->withMfa()->create(array_merge([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ], $userOverrides));

        OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => $level,
            'granted_by' => User::factory()->create(['is_super_admin' => true])->id,
            'granted_at' => now(),
        ]);

        return $this->actingAs($user)->withSession([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    private function grantFor(User $user): ?OpsAccessGrant
    {
        return OpsAccessGrant::query()->active()->where('user_id', $user->id)->first();
    }

    // ── 1. The gate ─────────────────────────────────────────────────────

    public function test_operator_reads_everything_the_viewer_reads(): void
    {
        $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)->get('/ops')->assertOk();
        $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)->get('/ops/applications')->assertOk();
        $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)->get('/ops/events')->assertOk();
        $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)->get('/ops/incidents')->assertOk();
        $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)->get('/ops/diagnostics')->assertOk();
    }

    public function test_operator_without_mfa_is_sent_to_mfa_setup(): void
    {
        // Same bar as viewers and super-admins — the tier changes WHAT
        // you can do, never the account-security bar.
        $user = User::factory()->create([ // no withMfa()
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => OpsAccessGrant::LEVEL_OPERATOR,
            'granted_at' => now(),
        ]);

        $this->actingAs($user)->get('/ops')->assertRedirect(route('mfa.setup'));
    }

    // ── 2. Route-level split ────────────────────────────────────────────

    public function test_operator_can_run_read_only_diagnostics(): void
    {
        // The one surface the tier exists for. sqlite connectivity is
        // healthy in the test environment; the run redirects to its
        // result page.
        $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)
            ->post('/ops/diagnostics/run', ['diagnostic' => 'database.connectivity'])
            ->assertRedirect();
    }

    public function test_viewer_still_cannot_run_diagnostics(): void
    {
        // The Iteration-5 guarantee, unchanged by the new tier.
        $this->asGrantee(OpsAccessGrant::LEVEL_VIEWER)
            ->post('/ops/diagnostics/run', ['diagnostic' => 'database.connectivity'])
            ->assertStatus(403);
    }

    public function test_regular_user_without_grant_still_gets_403_on_run(): void
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->post('/ops/diagnostics/run', ['diagnostic' => 'database.connectivity'])
            ->assertStatus(403);
    }

    public function test_operator_cannot_use_incident_lifecycle_actions(): void
    {
        $app = OpsApplication::create([
            'slug' => 'app-'.uniqid(),
            'name' => 'App '.uniqid(),
            'provider' => 'coolify',
            'provider_uuid' => 'uuid-'.uniqid(),
            'kind' => 'application',
            'environment' => 'production',
            'status' => 'running:healthy',
            'health' => 'running',
            'status_checked_at' => now(),
        ]);

        $incident = OpsIncident::create([
            'ops_application_id' => $app->id,
            'title' => 'Test incident',
            'severity' => 'error',
            'status' => 'open',
            'correlation_key' => 'corr-'.uniqid(),
            'first_event_at' => now(),
            'last_event_at' => now(),
        ]);

        $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)
            ->post("/ops/incidents/{$incident->id}/acknowledge")
            ->assertStatus(403);
        $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)
            ->post("/ops/incidents/{$incident->id}/resolve")
            ->assertStatus(403);

        $this->assertSame('open', $incident->fresh()->status);
    }

    public function test_operator_cannot_reach_actions_credentials_or_access_management(): void
    {
        $operator = $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR);

        $operator->get('/ops/actions')->assertStatus(403);
        $operator->get('/ops/actions/app.restart/confirm')->assertStatus(403);
        $operator->post('/ops/actions/app.restart')->assertStatus(403);
        $operator->get('/ops/credentials')->assertStatus(403);
        $operator->post('/ops/credentials/coolify-token/rotate')->assertStatus(403);
        $operator->get('/ops/access')->assertStatus(403);
        $operator->post('/ops/access/grant', ['user_id' => 1, 'level' => 'operator'])->assertStatus(403);

        // A second operator grant must never be creatable by an operator.
        $target = User::factory()->withMfa()->create(['is_super_admin' => false, 'email_verified_at' => now()]);
        $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)
            ->post('/ops/access/grant', ['user_id' => $target->id, 'level' => 'operator'])
            ->assertStatus(403);
        $this->assertNull($this->grantFor($target));
    }

    public function test_operator_diagnostic_runs_are_audited(): void
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);
        OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => OpsAccessGrant::LEVEL_OPERATOR,
            'granted_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->post('/ops/diagnostics/run', ['diagnostic' => 'database.connectivity'])
            ->assertRedirect();

        // The audit trail names the actor — delegating the run right
        // never means delegating away accountability.
        $audit = AdminAuditLog::where('action', 'ops.diagnostic.run')->first();
        $this->assertNotNull($audit);
        $this->assertSame($user->id, $audit->actor_id);
    }

    // ── 3. Kill-switch independence ─────────────────────────────────────

    public function test_operator_kill_switch_fail_closes_only_operators(): void
    {
        config(['ops.access.operator_enabled' => false]);

        // Operator: locked out of BOTH surfaces (the read gate treats a
        // disabled tier as no access; the run gate denies).
        $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)->get('/ops')->assertStatus(403);
        $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)
            ->post('/ops/diagnostics/run', ['diagnostic' => 'database.connectivity'])
            ->assertStatus(403);

        // Viewer: untouched.
        $this->asGrantee(OpsAccessGrant::LEVEL_VIEWER)->get('/ops')->assertOk();

        // Super-admin: untouched.
        $this->asMfaSuperAdmin()->get('/ops')->assertOk();
    }

    public function test_viewer_kill_switch_does_not_touch_operators(): void
    {
        config(['ops.access.viewer_enabled' => false]);

        $this->asGrantee(OpsAccessGrant::LEVEL_VIEWER)->get('/ops')->assertStatus(403);
        $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)->get('/ops')->assertOk();
    }

    public function test_revoked_operator_grant_loses_access_immediately(): void
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);
        $grant = OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => OpsAccessGrant::LEVEL_OPERATOR,
            'granted_at' => now(),
        ]);

        $acting = $this->actingAs($user)->withSession([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
        ]);

        $acting->get('/ops')->assertOk();

        $grant->forceFill(['revoked_at' => now()])->save();

        $acting->get('/ops')->assertStatus(403);
    }

    // ── 4. Grant management + atomic level changes ──────────────────────

    public function test_super_admin_grants_operator_tier_from_the_picker(): void
    {
        $target = User::factory()->withMfa()->create(['is_super_admin' => false, 'email_verified_at' => now()]);

        $this->asMfaSuperAdmin()
            ->post('/ops/access/grant', ['user_id' => $target->id, 'level' => 'operator'])
            ->assertRedirect(route('ops.access.index'));

        $grant = $this->grantFor($target);
        $this->assertNotNull($grant);
        $this->assertSame(OpsAccessGrant::LEVEL_OPERATOR, $grant->level);

        $audit = AdminAuditLog::where('action', 'ops.access.granted')->first();
        $this->assertNotNull($audit);
        $this->assertSame($target->id, $audit->payload['user_id']);
        $this->assertSame(OpsAccessGrant::LEVEL_OPERATOR, $audit->payload['level']);
    }

    public function test_grant_defaults_to_viewer_when_level_is_omitted(): void
    {
        // The Iteration-5 call shape keeps working.
        $target = User::factory()->withMfa()->create(['is_super_admin' => false, 'email_verified_at' => now()]);

        $this->asMfaSuperAdmin()
            ->post('/ops/access/grant', ['user_id' => $target->id])
            ->assertRedirect(route('ops.access.index'));

        $this->assertSame(OpsAccessGrant::LEVEL_VIEWER, $this->grantFor($target)->level);
    }

    public function test_unknown_level_fails_validation(): void
    {
        $target = User::factory()->withMfa()->create(['is_super_admin' => false, 'email_verified_at' => now()]);

        $this->asMfaSuperAdmin()
            ->post('/ops/access/grant', ['user_id' => $target->id, 'level' => 'supervisor'])
            ->assertSessionHasErrors('level');

        $this->assertNull($this->grantFor($target));
    }

    public function test_level_change_is_atomic_revoke_then_grant(): void
    {
        $target = User::factory()->withMfa()->create(['is_super_admin' => false, 'email_verified_at' => now()]);
        $admin = User::factory()->withMfa()->create(['is_super_admin' => true, 'email_verified_at' => now()]);

        $viewerGrant = OpsAccessGrant::create([
            'user_id' => $target->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
            'granted_by' => $admin->id,
            'granted_at' => now()->subDays(3),
        ]);

        $service = app(\App\Ops\Services\OpsAccessService::class);
        $result = $service->grant($target, $admin, OpsAccessGrant::LEVEL_OPERATOR);

        $this->assertTrue($result['ok']);

        // Exactly ONE active grant — never a window with zero or two.
        $this->assertSame(1, OpsAccessGrant::query()->active()->where('user_id', $target->id)->count());
        $this->assertSame(OpsAccessGrant::LEVEL_OPERATOR, $this->grantFor($target)->level);

        // The old row is closed (kept as history) and both transitions audited.
        $this->assertNotNull($viewerGrant->fresh()->revoked_at);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'ops.access.revoked']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'ops.access.granted']);

        // Downgrade back: same atomicity, other direction.
        $result = $service->grant($target, $admin, OpsAccessGrant::LEVEL_VIEWER);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, OpsAccessGrant::query()->active()->where('user_id', $target->id)->count());
        $this->assertSame(OpsAccessGrant::LEVEL_VIEWER, $this->grantFor($target)->level);
    }

    public function test_same_level_grant_is_still_rejected(): void
    {
        $target = User::factory()->withMfa()->create(['is_super_admin' => false, 'email_verified_at' => now()]);
        $admin = User::factory()->withMfa()->create(['is_super_admin' => true, 'email_verified_at' => now()]);

        OpsAccessGrant::create([
            'user_id' => $target->id,
            'level' => OpsAccessGrant::LEVEL_OPERATOR,
            'granted_by' => $admin->id,
            'granted_at' => now(),
        ]);

        $service = app(\App\Ops\Services\OpsAccessService::class);
        $result = $service->grant($target, $admin, OpsAccessGrant::LEVEL_OPERATOR);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, OpsAccessGrant::query()->active()->where('user_id', $target->id)->count());
    }

    public function test_access_page_renders_both_tiers_with_change_buttons(): void
    {
        $viewer = User::factory()->withMfa()->create(['is_super_admin' => false, 'email_verified_at' => now()]);
        $operator = User::factory()->withMfa()->create(['is_super_admin' => false, 'email_verified_at' => now()]);

        OpsAccessGrant::create(['user_id' => $viewer->id, 'level' => 'viewer', 'granted_at' => now()]);
        OpsAccessGrant::create(['user_id' => $operator->id, 'level' => 'operator', 'granted_at' => now()]);

        $response = $this->asMfaSuperAdmin()->get('/ops/access');

        $response->assertOk()
            ->assertSee('Active grants (2)', false)
            ->assertSee('Make operator', false)
            ->assertSee('Make viewer', false)
            // The grant form carries both tiers.
            ->assertSee('name="level"', false)
            ->assertSee('value="operator"', false)
            ->assertSee('value="viewer"', false);
    }

    // ── 5. UI honesty ───────────────────────────────────────────────────

    public function test_operator_sees_run_buttons_but_no_infrastructure_or_governance_surfaces(): void
    {
        $response = $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)->get('/ops/diagnostics');

        $response->assertOk()
            // The run surface is present...
            ->assertSee('Run diagnostic', false)
            // ...and correctly BADGED as the operator's tier.
            ->assertSee('>OPERATOR<', false)
            // The governance doors stay hidden (route-level 403s back this).
            ->assertDontSee('href="'.route('ops.actions.index').'"', false)
            ->assertDontSee('href="'.route('ops.credentials.index').'"', false)
            ->assertDontSee('href="'.route('ops.access.index').'"', false)
            // No VIEWER badge for this user.
            ->assertDontSee('>VIEWER<', false);
    }

    public function test_viewer_sees_the_read_only_placeholder_and_badge(): void
    {
        $response = $this->asGrantee(OpsAccessGrant::LEVEL_VIEWER)->get('/ops/diagnostics');

        $response->assertOk()
            ->assertSee('viewer (read-only)', false)
            ->assertSee('>VIEWER<', false)
            ->assertDontSee('>OPERATOR<', false);
    }

    public function test_operator_sees_run_checks_on_applications_but_not_restart(): void
    {
        $app = OpsApplication::create([
            'slug' => 'app-'.uniqid(),
            'name' => 'Containerized App',
            'provider' => 'coolify',
            'provider_uuid' => 'uuid-'.uniqid(),
            'kind' => 'application',
            'environment' => 'production',
            'status' => 'running:healthy',
            'health' => 'running',
            'status_checked_at' => now(),
        ]);

        $response = $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)->get('/ops/applications');

        $response->assertOk()
            ->assertSee('Run checks', false)
            // Restart is an infrastructure action — super-admin only.
            ->assertDontSee('Restart', false)
            ->assertDontSee('Sync now', false);
    }

    public function test_operator_sees_recommended_diagnostics_on_event_detail(): void
    {
        $app = OpsApplication::create([
            'slug' => 'app-'.uniqid(),
            'name' => 'App '.uniqid(),
            'provider' => 'coolify',
            'provider_uuid' => 'uuid-'.uniqid(),
            'kind' => 'application',
            'environment' => 'production',
            'status' => 'running:healthy',
            'health' => 'running',
            'status_checked_at' => now(),
        ]);

        $event = OpsEvent::create([
            'fingerprint' => sha1(uniqid('', true)),
            'ops_application_id' => $app->id,
            'source' => 'system',
            'category' => 'DATABASE',
            'severity' => 'error',
            'title' => 'SQLSTATE[HY000] connection refused',
            'status' => 'open',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'classification' => ['recommended_diagnostics' => ['database.connectivity']],
        ]);

        $response = $this->asGrantee(OpsAccessGrant::LEVEL_OPERATOR)->get('/ops/events/'.$event->id);

        $response->assertOk()
            // The recommended-diagnostics forms POST to the run route.
            ->assertSee('/ops/diagnostics/run', false)
            ->assertDontSee('viewer (read-only)', false);
    }

    // ── 6. OpsAccessContext ─────────────────────────────────────────────

    public function test_access_context_resolves_levels_and_mirrors_the_middleware(): void
    {
        $superAdmin = User::factory()->withMfa()->create(['is_super_admin' => true]);
        $operator = User::factory()->withMfa()->create(['is_super_admin' => false]);
        $viewer = User::factory()->withMfa()->create(['is_super_admin' => false]);
        $outsider = User::factory()->withMfa()->create(['is_super_admin' => false]);

        OpsAccessGrant::create(['user_id' => $operator->id, 'level' => 'operator', 'granted_at' => now()]);
        OpsAccessGrant::create(['user_id' => $viewer->id, 'level' => 'viewer', 'granted_at' => now()]);

        $this->assertSame('super_admin', OpsAccessContext::level($superAdmin));
        $this->assertSame('operator', OpsAccessContext::level($operator));
        $this->assertSame('viewer', OpsAccessContext::level($viewer));
        $this->assertNull(OpsAccessContext::level($outsider));
        $this->assertNull(OpsAccessContext::level(null));

        // The run right mirrors EnsureOpsOperator exactly.
        $this->assertTrue(OpsAccessContext::canRunDiagnostics($superAdmin));
        $this->assertTrue(OpsAccessContext::canRunDiagnostics($operator));
        $this->assertFalse(OpsAccessContext::canRunDiagnostics($viewer));
        $this->assertFalse(OpsAccessContext::canRunDiagnostics($outsider));

        // Infrastructure remains super-admin-only in the view layer.
        $this->assertTrue(OpsAccessContext::isOperator($superAdmin));
        $this->assertFalse(OpsAccessContext::isOperator($operator));
    }

    public function test_access_context_is_cached_per_request_and_flushable(): void
    {
        $user = User::factory()->withMfa()->create(['is_super_admin' => false]);
        OpsAccessGrant::create(['user_id' => $user->id, 'level' => 'operator', 'granted_at' => now()]);

        // Prime the cache.
        OpsAccessContext::level($user);

        // Revoking the grant must NOT be visible until the memo flushes
        // (the middleware re-checks the DB on every request anyway).
        OpsAccessGrant::query()->where('user_id', $user->id)->delete();
        $this->assertSame('operator', OpsAccessContext::level($user));

        OpsAccessContext::flush();
        $this->assertNull(OpsAccessContext::level($user));
    }
}
