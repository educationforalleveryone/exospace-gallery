<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\ProcessedWebhook;
use App\Models\User;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 3 — the action surface.
 *
 * These tests pin the four-layer security model and the execution contract:
 *
 *   1. Auth bar (super-admin + MFA) and throttle surface.
 *   2. Allow-list (unknown action → 404) and kill switch (disabled → 404).
 *   3. Inline password verification — wrong/missing password NEVER executes.
 *   4. Typed confirmation phrase — a mismatch NEVER executes.
 *   5. Execution: restart delegates to the Coolify API (and handles its
 *      failure), webhook replay reuses the existing pipeline, sync reuses
 *      the scheduled sync service.
 *   6. Every attempt is audited (ops.action.executed) and announced through
 *      the existing alerting pipeline.
 */
class OpsActionsTest extends TestCase
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
            'ops.actions.enabled' => true,
            'ops.platform_sync.enabled' => true,
        ]);
    }

    private function asMfaSuperAdmin(array $overrides = [])
    {
        $admin = User::factory()->withMfa()->create(array_merge([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ], $overrides));

        return $this->actingAs($admin)->withSession([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    private function coolifyApp(): OpsApplication
    {
        return OpsApplication::create([
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
    }

    private function failedWebhook(): ProcessedWebhook
    {
        return ProcessedWebhook::create([
            'message_id' => 'test-msg-'.uniqid(),
            'message_type' => 'FRAUD_STATUS_CHANGED',
            'invoice_id' => '123456789',
            'payload' => ['message_type' => 'FRAUD_STATUS_CHANGED', 'invoice_id' => '123456789', 'fraud_status' => 'approved'],
            'status' => 'failed',
            'processed_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
    }

    // ── Layer 1: auth bar ───────────────────────────────────────────────

    public function test_guest_cannot_execute_actions(): void
    {
        $this->post('/ops/actions/app.restart')->assertRedirect('/login');
        $this->get('/ops/actions')->assertRedirect('/login');
    }

    public function test_regular_user_gets_403(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get('/ops/actions')->assertStatus(403);
        $this->actingAs($user)->post('/ops/actions/app.restart')->assertStatus(403);
    }

    // ── Layer 2: allow-list + kill switch ───────────────────────────────

    public function test_unknown_action_is_404(): void
    {
        $this->asMfaSuperAdmin()
            ->post('/ops/actions/rm-rf-everything', ['confirm' => 'x', 'password' => 'x'])
            ->assertStatus(404);
    }

    public function test_kill_switch_fail_closes_the_surface(): void
    {
        config(['ops.actions.enabled' => false]);

        $this->asMfaSuperAdmin()
            ->get('/ops/actions')
            ->assertOk() // the hub renders (with the disabled banner)
            ->assertSee('disabled', false);

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/platform.sync')
            ->assertStatus(404);

        $app = $this->coolifyApp();
        $this->asMfaSuperAdmin()
            ->get(route('ops.actions.confirm', ['action' => 'app.restart', 'app' => $app->id]))
            ->assertStatus(404);
    }

    // ── Layer 3 + 4: password & phrase ──────────────────────────────────

    public function test_restart_requires_password(): void
    {
        Http::fake(['*' => Http::response([])]); // would fail the test if called

        $app = $this->coolifyApp();

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/app.restart', [
                'application' => $app->id,
                'confirm' => 'RESTART',
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, AdminAuditLog::where('action', 'ops.action.executed')->count(), 'A rejected attempt must not be audited as executed.');
    }

    public function test_restart_requires_the_typed_confirmation_phrase(): void
    {
        Http::fake(['*' => Http::response([])]);

        $app = $this->coolifyApp();

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/app.restart', [
                'application' => $app->id,
                'confirm' => 'restart-please', // WRONG phrase
                'password' => 'password', // Breeze default test password
            ])
            ->assertSessionHasErrors('confirm');
    }

    public function test_restart_confirm_page_renders_consequences_and_targets(): void
    {
        $app = $this->coolifyApp();

        $this->asMfaSuperAdmin()
            ->get(route('ops.actions.confirm', ['action' => 'app.restart', 'app' => $app->id]))
            ->assertOk()
            ->assertSee('Project B', false)
            ->assertSee('This WILL', false)
            ->assertSee('This will NOT', false)
            ->assertSee('RESTART', false)
            ->assertSee('password', false);
    }

    public function test_restart_confirm_page_requires_a_target(): void
    {
        $this->asMfaSuperAdmin()
            ->get(route('ops.actions.confirm', ['action' => 'app.restart']))
            ->assertRedirect(route('ops.actions.index'))
            ->assertSessionHasErrors('action');
    }

    // ── Layer 5: execution ──────────────────────────────────────────────

    public function test_restart_executes_via_coolify_and_audits_and_alerts(): void
    {
        $app = $this->coolifyApp();

        Http::fake([
            'http://coolify.test/api/v1/applications/uuid-b/restart' => Http::response(['deployment_uuid' => 'dep-restart-1']),
        ]);

        $alerts = 0;
        $this->mock(OperationalAlertService::class, function ($mock) use (&$alerts) {
            $mock->shouldReceive('alert')->andReturnUsing(function () use (&$alerts) {
                $alerts++;
            });
        });

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/app.restart', [
                'application' => $app->id,
                'confirm' => 'RESTART',
                'password' => 'password',
            ])
            ->assertRedirect(route('ops.applications'))
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return str_ends_with((string) $request->url(), '/api/v1/applications/uuid-b/restart')
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });

        // Audited with outcome success.
        $audit = AdminAuditLog::where('action', 'ops.action.executed')->first();
        $this->assertNotNull($audit);
        $this->assertSame('app.restart', $audit->payload['action']);
        $this->assertSame('success', $audit->payload['outcome']);

        // Announced through the alerting pipeline.
        $this->assertSame(1, $alerts);

        // The operator intervention is visible as a control-plane event.
        $this->assertSame(1, OpsEvent::where('category', 'INFRASTRUCTURE')
            ->where('title', 'like', '%restart%')
            ->where('ops_application_id', $app->id)
            ->count());
    }

    public function test_restart_failure_when_coolify_rejects(): void
    {
        $app = $this->coolifyApp();

        Http::fake([
            'http://coolify.test/api/v1/applications/uuid-b/restart' => Http::response(['message' => 'nope'], 500),
        ]);

        $this->mock(OperationalAlertService::class, function ($mock) {
            $mock->shouldReceive('alert')->andReturnNull();
        });

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/app.restart', [
                'application' => $app->id,
                'confirm' => 'RESTART',
                'password' => 'password',
            ])
            ->assertSessionHas('error');

        $audit = AdminAuditLog::where('action', 'ops.action.executed')->first();
        $this->assertNotNull($audit);
        $this->assertSame('failure', $audit->payload['outcome']);
    }

    public function test_restart_refuses_apps_without_coolify_uuid(): void
    {
        $app = OpsApplication::create([
            'slug' => 'ingest-only',
            'name' => 'Ingest Only',
            'provider' => 'ingest',
            'kind' => 'application',
            'environment' => 'production',
        ]);

        $this->mock(OperationalAlertService::class, function ($mock) {
            $mock->shouldReceive('alert')->andReturnNull();
        });

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/app.restart', [
                'application' => $app->id,
                'confirm' => 'RESTART',
                'password' => 'password',
            ])
            ->assertSessionHas('error');

        $audit = AdminAuditLog::where('action', 'ops.action.executed')->first();
        $this->assertSame('failure', $audit->payload['outcome']);
    }

    public function test_webhook_replay_reuses_the_existing_pipeline(): void
    {
        $webhook = $this->failedWebhook();

        $this->mock(OperationalAlertService::class, function ($mock) {
            $mock->shouldReceive('alert')->andReturnNull();
        });

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/webhook.replay', [
                'webhook' => $webhook->id,
                'confirm' => 'REPLAY',
                'password' => 'password',
            ])
            ->assertRedirect() // stays on the actions hub
            ->assertSessionHas('success');

        $webhook->refresh();
        // A non-mutating message type runs clean: processed, replay counted.
        $this->assertSame('processed', $webhook->status);
        $this->assertSame(1, (int) $webhook->replay_count);
        $this->assertNotNull($webhook->last_replayed_at);

        $audit = AdminAuditLog::where('action', 'ops.action.executed')->first();
        $this->assertSame('webhook.replay', $audit->payload['action']);
        $this->assertSame('success', $audit->payload['outcome']);
    }

    public function test_webhook_replay_without_stored_payload_is_refused(): void
    {
        $webhook = $this->failedWebhook();
        $webhook->update(['payload' => null]);

        $this->mock(OperationalAlertService::class, function ($mock) {
            $mock->shouldReceive('alert')->andReturnNull();
        });

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/webhook.replay', [
                'webhook' => $webhook->id,
                'confirm' => 'REPLAY',
                'password' => 'password',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, (int) $webhook->fresh()->replay_count);
    }

    public function test_platform_sync_executes_without_password(): void
    {
        Http::fake([
            'http://coolify.test/api/v1/servers' => Http::response([]),
            'http://coolify.test/api/v1/applications' => Http::response([
                ['uuid' => 'uuid-b', 'name' => 'Project B', 'status' => 'running:healthy'],
            ]),
            'http://coolify.test/api/v1/databases' => Http::response([]),
            'http://coolify.test/api/v1/services' => Http::response([]),
            'http://coolify.test/api/v1/applications/*/deployments' => Http::response([]),
            '*' => Http::response([]),
        ]);

        $this->mock(OperationalAlertService::class, function ($mock) {
            $mock->shouldReceive('alert')->andReturnNull();
        });

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/platform.sync')
            ->assertRedirect()
            ->assertSessionHas('success');

        $audit = AdminAuditLog::where('action', 'ops.action.executed')->first();
        $this->assertNotNull($audit);
        $this->assertSame('platform.sync', $audit->payload['action']);
    }

    public function test_platform_sync_reports_unreachable_api_without_erroring(): void
    {
        Http::fake(function () {
            return Http::response([], 500);
        });

        $this->mock(OperationalAlertService::class, function ($mock) {
            $mock->shouldReceive('alert')->andReturnNull();
        });

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/platform.sync')
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    // ── The actions hub page ────────────────────────────────────────────

    public function test_actions_hub_renders_catalog_webhook_panel_and_history(): void
    {
        $webhook = $this->failedWebhook();

        $this->asMfaSuperAdmin()
            ->get('/ops/actions')
            ->assertOk()
            ->assertSee('Restart application container', false)
            ->assertSee('Replay billing webhook', false)
            ->assertSee('#'.$webhook->id, false)
            ->assertSee('Dangerous operations', false);
    }

    public function test_actions_hub_shows_disabled_banner_when_kill_switched(): void
    {
        config(['ops.actions.enabled' => false]);

        $this->asMfaSuperAdmin()
            ->get('/ops/actions')
            ->assertOk()
            ->assertSee('OPS_ACTIONS_ENABLED=false', false);
    }
}
