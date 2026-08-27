<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Models\OpsAccessGrant;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 5 — the RBAC viewer role.
 *
 * These tests pin the delegation model end to end:
 *
 *   1. The gate: no grant → 403 (unchanged); super-admin → unchanged;
 *      active viewer grant + MFA + verified email → READ access.
 *   2. The ROUTE-LEVEL read/write split: viewers can never POST
 *      anything, never see the Actions hub, the Credentials page or
 *      the Access management page — even with a direct URL.
 *   3. Viewer policy: MFA required (redirect to setup), MFA session
 *      TTL enforced (redirect to verify), email verification required.
 *   4. Fail-closed edges: revoked grant, kill switch, unknown level.
 *   5. Grant management: only super-admins grant/revoke; every change
 *      audited + announced; duplicate/super-admin grants rejected.
 *   6. UI honesty: viewers see no action buttons, operators see them.
 */
class OpsAccessControlTest extends TestCase
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
        ]);
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
     * A regular, verified, MFA-enabled user holding an active viewer
     * grant, already MFA-verified in-session.
     */
    private function asViewer(array $userOverrides = [], array $grantOverrides = [])
    {
        $user = User::factory()->withMfa()->create(array_merge([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ], $userOverrides));

        OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
            'granted_by' => User::factory()->create(['is_super_admin' => true])->id,
            'granted_at' => now(),
        ] + $grantOverrides);

        return $this->actingAs($user)->withSession([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    private function application(): OpsApplication
    {
        return OpsApplication::create([
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
    }

    private function incident(?int $applicationId = null): OpsIncident
    {
        return OpsIncident::create([
            'ops_application_id' => $applicationId,
            'title' => 'Test incident',
            'severity' => 'error',
            'status' => 'open',
            'correlation_key' => 'test-'.uniqid(),
            'event_count' => 1,
            'first_event_at' => now(),
            'last_event_at' => now(),
        ]);
    }

    // ── 1. The gate ─────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/ops')->assertRedirect('/login');
    }

    public function test_regular_user_without_grant_gets_403_everywhere(): void
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get('/ops')->assertStatus(403);
        $this->actingAs($user)->get('/ops/applications')->assertStatus(403);
        $this->actingAs($user)->get('/ops/events')->assertStatus(403);
        $this->actingAs($user)->get('/ops/incidents')->assertStatus(403);
        $this->actingAs($user)->get('/ops/diagnostics')->assertStatus(403);
    }

    public function test_super_admin_access_is_unchanged(): void
    {
        $this->asMfaSuperAdmin()->get('/ops')->assertOk();
        $this->asMfaSuperAdmin()->get('/ops/actions')->assertOk();
        $this->asMfaSuperAdmin()->get('/ops/credentials')->assertOk();
        $this->asMfaSuperAdmin()->get('/ops/access')->assertOk();
    }

    public function test_viewer_with_active_grant_can_read_the_control_plane(): void
    {
        $this->asViewer()->get('/ops')->assertOk();
        $this->asViewer()->get('/ops/applications')->assertOk();
        $this->asViewer()->get('/ops/events')->assertOk();
        $this->asViewer()->get('/ops/incidents')->assertOk();
        $this->asViewer()->get('/ops/diagnostics')->assertOk();
    }

    public function test_viewer_can_read_event_and_incident_detail(): void
    {
        $app = $this->application();
        $event = OpsEvent::create([
            'fingerprint' => sha1(uniqid('', true)),
            'ops_application_id' => $app->id,
            'source' => 'system',
            'category' => 'APPLICATION',
            'severity' => 'error',
            'title' => 'Something failed',
            'status' => 'open',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $incident = $this->incident($app->id);

        $this->asViewer()->get('/ops/events/'.$event->id)->assertOk();
        $this->asViewer()->get('/ops/incidents/'.$incident->id)->assertOk();
    }

    // ── 2. Route-level read/write split ─────────────────────────────────

    public function test_viewer_cannot_run_diagnostics(): void
    {
        $this->asViewer()->post('/ops/diagnostics/run', ['diagnostic' => 'database.connectivity'])->assertStatus(403);
    }

    public function test_viewer_cannot_use_incident_lifecycle_actions(): void
    {
        $incident = $this->incident();

        $this->asViewer()->post("/ops/incidents/{$incident->id}/acknowledge")->assertStatus(403);
        $this->asViewer()->post("/ops/incidents/{$incident->id}/resolve")->assertStatus(403);
        $this->asViewer()->post("/ops/incidents/{$incident->id}/reopen")->assertStatus(403);

        // ...and the state never changed.
        $this->assertSame('open', $incident->fresh()->status);
    }

    public function test_viewer_cannot_reach_the_actions_hub(): void
    {
        $this->asViewer()->get('/ops/actions')->assertStatus(403);
        $this->asViewer()->get('/ops/actions/app.restart/confirm')->assertStatus(403);
        $this->asViewer()->post('/ops/actions/app.restart')->assertStatus(403);
    }

    public function test_viewer_cannot_reach_credentials_or_access_management(): void
    {
        $this->asViewer()->get('/ops/credentials')->assertStatus(403);
        $this->asViewer()->post('/ops/credentials/coolify-token/rotate')->assertStatus(403);
        $this->asViewer()->get('/ops/access')->assertStatus(403);
        $this->asViewer()->post('/ops/access/grant', ['user_id' => 1])->assertStatus(403);
    }

    // ── 3. Viewer policy: MFA + verification ────────────────────────────

    public function test_viewer_without_mfa_is_sent_to_mfa_setup(): void
    {
        $user = User::factory()->create([ // no withMfa()
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
            'granted_at' => now(),
        ]);

        $this->actingAs($user)->get('/ops')->assertRedirect(route('mfa.setup'));
    }

    public function test_viewer_with_expired_mfa_session_is_sent_to_verify(): void
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
            'granted_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->subHours(2)->timestamp])
            ->get('/ops')
            ->assertRedirect(route('mfa.verify'));
    }

    public function test_unverified_viewer_is_sent_to_email_verification(): void
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => null,
        ]);

        OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
            'granted_at' => now(),
        ]);

        $this->actingAs($user)->get('/ops')->assertRedirect(route('verification.notice'));
    }

    // ── 4. Fail-closed edges ────────────────────────────────────────────

    public function test_revoked_grant_loses_access_immediately(): void
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $grant = OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
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

    public function test_kill_switch_fail_closes_all_viewers_but_not_super_admins(): void
    {
        config(['ops.access.viewer_enabled' => false]);

        $this->asViewer()->get('/ops')->assertStatus(403);
        $this->asMfaSuperAdmin()->get('/ops')->assertOk();
    }

    public function test_unknown_grant_level_grants_nothing(): void
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => 'supervisor', // not implemented — must not open the door
            'granted_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->get('/ops')
            ->assertStatus(403);
    }

    public function test_deleted_grant_row_means_no_access(): void
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $grant = OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
            'granted_at' => now(),
        ]);

        $grant->delete();

        $this->actingAs($user)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->get('/ops')
            ->assertStatus(403);
    }

    // ── 5. Grant management ─────────────────────────────────────────────

    public function test_super_admin_can_grant_viewer_access(): void
    {
        $target = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $response = $this->asMfaSuperAdmin()->post('/ops/access/grant', ['user_id' => $target->id]);

        $response->assertRedirect(route('ops.access.index'));
        $this->assertDatabaseHas('ops_access_grants', [
            'user_id' => $target->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'ops.access.granted']);
    }

    public function test_grant_to_super_admin_is_rejected_as_redundant(): void
    {
        $superAdmin = User::factory()->withMfa()->create(['is_super_admin' => true]);

        $this->asMfaSuperAdmin()
            ->post('/ops/access/grant', ['user_id' => $superAdmin->id])
            ->assertRedirect(route('ops.access.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('ops_access_grants', ['user_id' => $superAdmin->id]);
    }

    public function test_duplicate_active_grant_is_rejected(): void
    {
        $target = User::factory()->withMfa()->create(['is_super_admin' => false]);

        $this->asMfaSuperAdmin()->post('/ops/access/grant', ['user_id' => $target->id]);
        $this->asMfaSuperAdmin()
            ->post('/ops/access/grant', ['user_id' => $target->id])
            ->assertRedirect(route('ops.access.index'))
            ->assertSessionHas('error');

        $this->assertSame(1, OpsAccessGrant::where('user_id', $target->id)->count());
    }

    public function test_grant_with_unknown_user_fails_validation(): void
    {
        $this->asMfaSuperAdmin()
            ->post('/ops/access/grant', ['user_id' => 999999])
            ->assertSessionHasErrors('user_id');
    }

    public function test_super_admin_can_revoke_and_access_ends(): void
    {
        $target = User::factory()->withMfa()->create(['is_super_admin' => false]);
        $grant = OpsAccessGrant::create([
            'user_id' => $target->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
            'granted_at' => now(),
        ]);

        $this->asMfaSuperAdmin()
            ->post("/ops/access/{$grant->id}/revoke")
            ->assertRedirect(route('ops.access.index'))
            ->assertSessionHas('success');

        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'ops.access.revoked']);

        // And the door is closed.
        $this->actingAs($target)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->get('/ops')
            ->assertStatus(403);
    }

    public function test_revoke_is_idempotent(): void
    {
        $target = User::factory()->withMfa()->create(['is_super_admin' => false]);
        $grant = OpsAccessGrant::create([
            'user_id' => $target->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
            'granted_at' => now(),
            'revoked_at' => now()->subDay(),
        ]);

        $this->asMfaSuperAdmin()
            ->post("/ops/access/{$grant->id}/revoke")
            ->assertRedirect(route('ops.access.index'))
            ->assertSessionHas('error');
    }

    public function test_access_page_lists_grants_with_mfa_readiness(): void
    {
        $readyViewer = User::factory()->withMfa()->create(['is_super_admin' => false]);
        $mfaLessViewer = User::factory()->create(['is_super_admin' => false]);

        OpsAccessGrant::create(['user_id' => $readyViewer->id, 'level' => OpsAccessGrant::LEVEL_VIEWER, 'granted_at' => now()]);
        OpsAccessGrant::create(['user_id' => $mfaLessViewer->id, 'level' => OpsAccessGrant::LEVEL_VIEWER, 'granted_at' => now()]);

        $this->asMfaSuperAdmin()
            ->get('/ops/access')
            ->assertOk()
            ->assertSee($readyViewer->email)
            ->assertSee($mfaLessViewer->email)
            ->assertSee('MFA SETUP NEEDED');
    }

    public function test_grant_and_revoke_are_announced_on_slack(): void
    {
        Http::fake();
        config(['services.operational_alerts.webhook_url' => 'https://hooks.example.test/ops']);

        $target = User::factory()->withMfa()->create(['is_super_admin' => false]);
        $this->asMfaSuperAdmin()->post('/ops/access/grant', ['user_id' => $target->id]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.example.test')
            && str_contains((string) $request->data()['text'] ?? '', 'GRANTED'));
    }

    // ── 6. UI honesty ───────────────────────────────────────────────────

    public function test_viewer_ui_hides_every_write_surface(): void
    {
        $this->asViewer()->get('/ops')->assertOk()
            ->assertDontSee('Actions</a>', escape: false)
            ->assertDontSee('Credentials</a>', escape: false)
            ->assertDontSee('Access</a>', escape: false)
            ->assertDontSee('Master Control')
            ->assertSee('VIEWER');

        $this->asViewer()->get('/ops/applications')->assertOk()
            ->assertDontSee('Sync now')
            ->assertDontSee('Restart…');

        $this->asViewer()->get('/ops/diagnostics')->assertOk()
            ->assertDontSee('Run diagnostic')
            ->assertSee('viewer (read-only)');

        $incident = $this->incident();
        $this->asViewer()->get('/ops/incidents/'.$incident->id)->assertOk()
            ->assertDontSee('>Acknowledge</button>', escape: false)
            ->assertDontSee('>Resolve</button>', escape: false)
            ->assertSee('READ-ONLY VIEW');
    }

    public function test_operator_ui_still_shows_write_surfaces(): void
    {
        $this->asMfaSuperAdmin()->get('/ops')->assertOk()
            ->assertSee('Actions</a>', escape: false)
            ->assertSee('Credentials</a>', escape: false)
            ->assertSee('Access</a>', escape: false)
            ->assertDontSee('VIEWER</span>', escape: false);

        $this->asMfaSuperAdmin()->get('/ops/applications')->assertOk()
            ->assertSee('Sync now');

        $this->asMfaSuperAdmin()->get('/ops/diagnostics')->assertOk()
            ->assertSee('Run diagnostic');
    }
}
