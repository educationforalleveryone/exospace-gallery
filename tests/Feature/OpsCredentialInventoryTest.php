<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Models\OpsCredential;
use App\Ops\Services\OpsCredentialInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 5 — the credential inventory & rotation ledger.
 *
 * These tests pin the governance surface's three contracts:
 *
 *   1. The catalog: complete (the §15 nine + the OpsCenter-era tokens),
 *      every entry well-formed, and the inventory NEVER carries a secret
 *      value — only presence booleans, timestamps and cadences.
 *   2. The status logic: exposed-never-rotated → ROTATE NOW; overdue /
 *      due-soon / ok around the per-credential cadence; untracked for
 *      the optional tokens; §15-first ordering.
 *   3. The ledger: markRotated upserts one row per key (no duplicates),
 *      rejects unknown keys, audits (ops.credential.rotated) and
 *      announces on Slack — and the page renders it all WITHOUT ever
 *      displaying a configured value.
 */
class OpsCredentialInventoryTest extends TestCase
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

    // ── 1. The catalog ──────────────────────────────────────────────────

    public function test_catalog_covers_the_section_15_nine_plus_opscenter_tokens(): void
    {
        $keys = array_column(OpsCredentialInventoryService::CATALOG, 'key');

        // §15 items 1–9:
        $this->assertContains('db-password', $keys);
        $this->assertContains('app-key', $keys);
        $this->assertContains('coolify-token', $keys);
        $this->assertContains('slack-webhooks', $keys);
        $this->assertContains('r2-keys', $keys);
        $this->assertContains('twocheckout-secrets', $keys);
        $this->assertContains('sentry-dsn', $keys);
        $this->assertContains('resend-key', $keys);
        $this->assertContains('metrics-webhook-tokens', $keys);
        // OpsCenter-era optional surfaces + backup encryption:
        $this->assertContains('ops-ingest-tokens', $keys);
        $this->assertContains('sentry-api-token', $keys);
        $this->assertContains('backup-password', $keys);
    }

    public function test_every_catalog_entry_is_well_formed(): void
    {
        foreach (OpsCredentialInventoryService::CATALOG as $entry) {
            $this->assertNotEmpty($entry['key']);
            $this->assertNotEmpty($entry['name']);
            $this->assertNotEmpty($entry['category']);
            $this->assertNotEmpty($entry['env']);
            $this->assertNotFalse(filter_var($entry['key'], FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^[a-z0-9-]+$/']]));
            $this->assertTrue($entry['cadence'] === null || $entry['cadence'] > 0);
            $this->assertIsBool($entry['exposed']);
            $this->assertNotEmpty($entry['guidance']);
        }

        // Keys are unique (they key the ledger row).
        $keys = array_column(OpsCredentialInventoryService::CATALOG, 'key');
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_inventory_reports_configured_presence_not_values(): void
    {
        config([
            'services.coolify.api_token' => 'super-secret-coolify-token-value',
            'ops.sentry.api_token' => null,
        ]);

        $items = collect(app(OpsCredentialInventoryService::class)->inventory()['items']);

        $coolify = $items->firstWhere('key', 'coolify-token');
        $this->assertTrue($coolify['configured']);
        $this->assertSame('COOLIFY_API_TOKEN', $coolify['env'][0]);

        $sentryApi = $items->firstWhere('key', 'sentry-api-token');
        $this->assertFalse($sentryApi['configured']);
    }

    public function test_inventory_never_carries_a_secret_value(): void
    {
        config([
            'services.coolify.api_token' => 'VALUE-THAT-MUST-NEVER-APPEAR',
            'backup.backup.password' => 'ANOTHER-FORBIDDEN-VALUE',
        ]);

        $payload = json_encode(app(OpsCredentialInventoryService::class)->inventory());

        $this->assertStringNotContainsString('VALUE-THAT-MUST-NEVER-APPEAR', $payload);
        $this->assertStringNotContainsString('ANOTHER-FORBIDDEN-VALUE', $payload);
    }

    // ── 2. Status logic ─────────────────────────────────────────────────

    public function test_exposed_and_never_rotated_is_rotate_now(): void
    {
        $items = collect(app(OpsCredentialInventoryService::class)->inventory()['items']);
        $exposed = $items->firstWhere('key', 'db-password');

        $this->assertTrue($exposed['exposed']);
        $this->assertNull($exposed['last_rotated_at']);
        $this->assertSame('rotate_now', $exposed['status']);
    }

    public function test_optional_and_never_rotated_is_untracked_not_alarming(): void
    {
        $items = collect(app(OpsCredentialInventoryService::class)->inventory()['items']);
        $ingest = $items->firstWhere('key', 'ops-ingest-tokens');

        $this->assertFalse($ingest['exposed']);
        $this->assertSame('untracked', $ingest['status']);
    }

    public function test_rotation_within_cadence_is_ok(): void
    {
        OpsCredential::create([
            'key' => 'coolify-token',
            'last_rotated_at' => now()->subDays(10),
        ]);

        $items = collect(app(OpsCredentialInventoryService::class)->inventory()['items']);
        $this->assertSame('ok', $items->firstWhere('key', 'coolify-token')['status']);
    }

    public function test_rotation_beyond_cadence_is_overdue(): void
    {
        OpsCredential::create([
            'key' => 'coolify-token', // cadence 90
            'last_rotated_at' => now()->subDays(91),
        ]);

        $items = collect(app(OpsCredentialInventoryService::class)->inventory()['items']);
        $this->assertSame('overdue', $items->firstWhere('key', 'coolify-token')['status']);
    }

    public function test_rotation_near_cadence_edge_is_due_soon(): void
    {
        OpsCredential::create([
            'key' => 'slack-webhooks', // cadence 180, due-soon window 14 → 167..180
            'last_rotated_at' => now()->subDays(170),
        ]);

        $items = collect(app(OpsCredentialInventoryService::class)->inventory()['items']);
        $this->assertSame('due_soon', $items->firstWhere('key', 'slack-webhooks')['status']);
    }

    public function test_policy_driven_credential_is_ok_after_any_rotation(): void
    {
        OpsCredential::create([
            'key' => 'app-key', // cadence null
            'last_rotated_at' => now()->subDays(400),
        ]);

        $items = collect(app(OpsCredentialInventoryService::class)->inventory()['items']);
        $this->assertSame('ok', $items->firstWhere('key', 'app-key')['status']);
    }

    public function test_rotate_now_items_sort_first(): void
    {
        OpsCredential::create(['key' => 'db-password', 'last_rotated_at' => now()]); // now OK
        // app-key + coolify-token remain rotate_now (exposed, never rotated)

        $items = app(OpsCredentialInventoryService::class)->inventory()['items'];

        // The most urgent class leads the page...
        $this->assertSame('rotate_now', $items[0]['status']);
        // ...and the just-rotated credential is no longer at the top.
        $positions = array_flip(array_column($items, 'key'));
        $this->assertGreaterThan(
            $positions['coolify-token'],
            $positions['db-password'],
            'rotated credentials must rank below rotate-now ones',
        );
    }

    // ── 3. The ledger ───────────────────────────────────────────────────

    public function test_mark_rotated_creates_and_then_updates_one_row(): void
    {
        $actor = User::factory()->create();

        app(OpsCredentialInventoryService::class)->markRotated('db-password', $actor);
        $this->assertSame(1, OpsCredential::where('key', 'db-password')->count());

        app(OpsCredentialInventoryService::class)->markRotated('db-password', $actor, 'rotated again');
        $this->assertSame(1, OpsCredential::where('key', 'db-password')->count());
        $this->assertSame('rotated again', OpsCredential::where('key', 'db-password')->first()->notes);
    }

    public function test_mark_rotated_rejects_unknown_keys(): void
    {
        $actor = User::factory()->create();

        $result = app(OpsCredentialInventoryService::class)->markRotated('not-a-real-key', $actor);

        $this->assertFalse($result['ok']);
        $this->assertDatabaseMissing('ops_credentials', ['key' => 'not-a-real-key']);
    }

    public function test_mark_rotated_is_audited_and_announced(): void
    {
        Http::fake();
        config(['services.operational_alerts.webhook_url' => 'https://hooks.example.test/ops']);
        $actor = User::factory()->create();

        app(OpsCredentialInventoryService::class)->markRotated('coolify-token', $actor, 'via Coolify profile');

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'ops.credential.rotated',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.example.test')
            && str_contains((string) ($request->data()['text'] ?? ''), 'coolify-token'));
    }

    public function test_audit_payload_never_contains_the_note_value_from_config(): void
    {
        config(['services.coolify.api_token' => 'TOP-SECRET-TOKEN']);

        $actor = User::factory()->create();
        app(OpsCredentialInventoryService::class)->markRotated('coolify-token', $actor);

        $log = AdminAuditLog::where('action', 'ops.credential.rotated')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('TOP-SECRET-TOKEN', json_encode($log->payload));
    }

    // ── 4. The page ─────────────────────────────────────────────────────

    public function test_credentials_page_requires_super_admin(): void
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->get('/ops/credentials')
            ->assertStatus(403);

        $this->asMfaSuperAdmin()->get('/ops/credentials')->assertOk();
    }

    public function test_credentials_page_renders_the_checklist(): void
    {
        config(['services.coolify.api_token' => 'RENDER-SECRET-MUST-NOT-SHOW']);

        $response = $this->asMfaSuperAdmin()->get('/ops/credentials')->assertOk();

        $response->assertSee('Credentials')
            ->assertSee('ROTATE NOW')
            ->assertSee('COOLIFY_API_TOKEN')
            ->assertSee('I rotated this');
        $this->assertStringNotContainsString('RENDER-SECRET-MUST-NOT-SHOW', $response->getContent());
    }

    public function test_credentials_page_shows_green_after_rotation_recorded(): void
    {
        OpsCredential::create([
            'key' => 'db-password',
            'last_rotated_at' => now(),
            'notes' => 'rotated in DO panel',
        ]);

        // The ledger row renders on the page (note + recency), proving
        // the rotation is visible to the next operator.
        $this->asMfaSuperAdmin()->get('/ops/credentials')
            ->assertOk()
            ->assertSee('rotated in DO panel')
            ->assertSee('day(s) ago');
    }

    public function test_operator_can_record_a_rotation_from_the_page(): void
    {
        $this->asMfaSuperAdmin()
            ->post('/ops/credentials/db-password/rotate', ['note' => 'rotated in DO panel'])
            ->assertRedirect(route('ops.credentials.index'))
            ->assertSessionHas('success');

        $row = OpsCredential::where('key', 'db-password')->first();
        $this->assertNotNull($row);
        $this->assertSame('rotated in DO panel', $row->notes);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'ops.credential.rotated']);
    }

    public function test_recording_rotation_for_unknown_key_fails_soft(): void
    {
        $this->asMfaSuperAdmin()
            ->post('/ops/credentials/definitely-not-a-key/rotate', [])
            ->assertRedirect(route('ops.credentials.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('ops_credentials', ['key' => 'definitely-not-a-key']);
    }

    public function test_rotation_note_is_length_capped(): void
    {
        $this->asMfaSuperAdmin()
            ->post('/ops/credentials/db-password/rotate', ['note' => str_repeat('x', 300)])
            ->assertSessionHasErrors('note');
    }
}
