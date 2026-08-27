<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Models\OpsApplication;
use App\Ops\Services\SentryApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 8, Feature A — the Coolify-app ↔ Sentry-project
 * mapping and the per-application trend column.
 *
 * These tests pin:
 *
 *   1. The migration: the nullable sentry_project_slug column exists
 *      (null by default — zero day-one behavior change).
 *   2. The mapping write path: super-admin-only at the ROUTE level
 *      (viewer AND operator 403), validated (slug shape, length),
 *      case-normalized, audited as ops.sentry.mapping with old → new,
 *      clearable via empty input, idempotent on unchanged input.
 *   3. SentryApiClient::trendFor(): per-project cache key (a failing
 *      project must not poison the org trend or siblings), the project
 *      query parameter is sent, unconfigured/empty-slug short-circuits
 *      without a network call, errors cached.
 *   4. The Applications page: the Sentry column renders a sparkline for
 *      a mapped+configured app, "API error" for a failing project,
 *      honest muted states for unmapped/unconfigured, and the mapping
 *      panel renders for super-admins ONLY (viewers see neither the
 *      panel nor any form markup).
 */
class OpsSentryMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();

        config([
            'services.operational_alerts.webhook_url' => 'https://slack.test/hook',
            // Determinism: neutralize whatever the dev box's .env carries.
            'ops.sentry.api_token' => null,
            'ops.sentry.org' => null,
            'ops.sentry.projects' => [],
        ]);

        // Cache isolation: trendFor()/trend() cache per key, and the
        // SAME slug may be exercised by a unit test and a page test with
        // different fakes — a stale cache entry would leak across.
        Cache::flush();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function app(array $overrides = []): OpsApplication
    {
        return OpsApplication::create(array_merge([
            'slug' => 'app-'.uniqid(),
            'name' => 'App '.uniqid(),
            'provider' => 'coolify',
            'kind' => 'application',
            'environment' => 'production',
            'status' => 'running:healthy',
            'health' => 'running',
        ], $overrides));
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

    private function asTier(string $level)
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        \App\Ops\Models\OpsAccessGrant::create([
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

    private function configureSentry(): void
    {
        config([
            'ops.sentry.api_token' => 'test-token-value',
            'ops.sentry.org' => 'test-org',
            'ops.sentry.base_url' => 'https://sentry.test',
        ]);
    }

    // ── 1. Migration ────────────────────────────────────────────────────

    public function test_migration_adds_nullable_sentry_project_slug_column(): void
    {
        $application = $this->app();

        $this->assertNull($application->sentry_project_slug, 'The column must default to NULL (unmapped).');
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('ops_applications', 'sentry_project_slug'),
            'The sentry_project_slug column must exist on ops_applications.',
        );

        // Round-trip: the column is fillable and persists.
        $application->sentry_project_slug = 'exospace-production';
        $application->save();
        $this->assertSame(
            'exospace-production',
            $application->fresh()->sentry_project_slug,
            'sentry_project_slug must be fillable and persistable.',
        );
    }

    // ── 2. The mapping write path ───────────────────────────────────────

    public function test_super_admin_can_set_a_mapping_and_it_is_audited(): void
    {
        $application = $this->app();

        $response = $this->asMfaSuperAdmin()
            ->post(route('ops.applications.sentry', $application), [
                'sentry_project_slug' => 'exospace-production',
            ]);

        $response->assertRedirect(route('ops.applications'));
        $this->assertSame('exospace-production', $application->fresh()->sentry_project_slug);

        $audit = AdminAuditLog::query()->where('action', 'ops.sentry.mapping')->latest('id')->first();
        $this->assertNotNull($audit, 'The mapping change must be audited.');
        $this->assertSame($application->id, $audit->payload['application_id'] ?? null);
        $this->assertSame('exospace-production', $audit->payload['new'] ?? null);
        $this->assertArrayHasKey('old', $audit->payload);
        $this->assertNull($audit->payload['old'], 'old must be null for a first mapping.');
    }

    public function test_viewer_and_operator_cannot_set_a_mapping(): void
    {
        $application = $this->app();

        $this->asTier('viewer')
            ->post(route('ops.applications.sentry', $application), ['sentry_project_slug' => 'nope'])
            ->assertForbidden();

        $this->asTier('operator')
            ->post(route('ops.applications.sentry', $application), ['sentry_project_slug' => 'nope'])
            ->assertForbidden();

        $this->assertNull($application->fresh()->sentry_project_slug, 'Nothing may change on a 403.');
        $this->assertSame(0, AdminAuditLog::where('action', 'ops.sentry.mapping')->count());
    }

    public function test_invalid_slugs_are_rejected(): void
    {
        $application = $this->app();

        $this->asMfaSuperAdmin()
            ->post(route('ops.applications.sentry', $application), ['sentry_project_slug' => 'Not A Slug!'])
            ->assertSessionHasErrors('sentry_project_slug');

        $this->asMfaSuperAdmin()
            ->post(route('ops.applications.sentry', $application), ['sentry_project_slug' => str_repeat('a', 101)])
            ->assertSessionHasErrors('sentry_project_slug');

        $this->assertNull($application->fresh()->sentry_project_slug, 'Rejected input must never be written.');
    }

    public function test_uppercase_input_is_normalized_to_lowercase(): void
    {
        $application = $this->app();

        $this->asMfaSuperAdmin()
            ->post(route('ops.applications.sentry', $application), ['sentry_project_slug' => 'Exospace-Production']);

        $this->assertSame(
            'exospace-production',
            $application->fresh()->sentry_project_slug,
            'Sentry slugs are lowercase — a pasted uppercase slug must be normalized.',
        );
    }

    public function test_empty_input_clears_the_mapping(): void
    {
        $application = $this->app(['sentry_project_slug' => 'old-project']);

        $this->asMfaSuperAdmin()
            ->post(route('ops.applications.sentry', $application), ['sentry_project_slug' => '']);

        $this->assertNull($application->fresh()->sentry_project_slug, 'Empty input must CLEAR the mapping.');

        $audit = AdminAuditLog::query()->where('action', 'ops.sentry.mapping')->latest('id')->first();
        $this->assertSame('old-project', $audit->payload['old'] ?? null);
        $this->assertArrayHasKey('new', $audit->payload);
        $this->assertNull($audit->payload['new']);
    }

    public function test_unchanged_input_is_a_no_op_without_audit_noise(): void
    {
        $application = $this->app(['sentry_project_slug' => 'same-project']);

        $this->asMfaSuperAdmin()
            ->post(route('ops.applications.sentry', $application), ['sentry_project_slug' => 'same-project']);

        $this->assertSame('same-project', $application->fresh()->sentry_project_slug);
        $this->assertSame(
            0,
            AdminAuditLog::where('action', 'ops.sentry.mapping')->count(),
            'An unchanged mapping must not generate an audit row.',
        );
    }

    // ── 3. SentryApiClient::trendFor() ──────────────────────────────────

    public function test_trend_for_sends_the_project_parameter_and_uses_its_own_cache_key(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake([
            'sentry.test/*' => Http::response([
                'data' => [
                    [now()->timestamp - 3600, ['count' => 5]],
                    [now()->timestamp, ['count' => 2]],
                ],
            ]),
        ]);

        $client = app(SentryApiClient::class);
        $result = $client->trendFor('exospace-production');

        $this->assertTrue($result['configured']);
        $this->assertSame(7, $result['total']);
        $this->assertArrayHasKey('project', $result, 'The payload must identify which project it describes.');

        Http::assertSent(function ($request) {
            if (! str_contains((string) $request->url(), 'events-stats')) {
                return false;
            }

            // The project filter must arrive as the mapped slug — not the
            // config-wide list, not org-wide.
            return in_array('exospace-production', (array) ($request->data()['project'] ?? []), true);
        });

        // Second call: served from the per-project cache — no new request.
        $client->trendFor('exospace-production');
        Http::assertSentCount(1);
    }

    public function test_a_failing_project_does_not_poison_the_org_trend_or_siblings(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake(function ($request) {
            $url = (string) $request->url();
            $project = (array) ($request->data()['project'] ?? []);

            if (in_array('broken-project', $project, true)) {
                return Http::response(['detail' => 'not found'], 404);
            }

            return Http::response([
                'data' => [[now()->timestamp, ['count' => 4]]],
            ]);
        });

        $client = app(SentryApiClient::class);

        $broken = $client->trendFor('broken-project');
        $this->assertArrayHasKey('error', $broken, 'The 404 must surface as an honest error.');

        $healthy = $client->trendFor('healthy-project');
        $this->assertArrayNotHasKey('error', $healthy, 'A sibling project must not inherit the failure.');
        $this->assertSame(4, $healthy['total']);

        $org = $client->trend();
        $this->assertArrayNotHasKey('error', $org, 'The org-wide trend must not be poisoned either.');
        $this->assertSame(4, $org['total']);
    }

    public function test_trend_for_short_circuits_when_unconfigured_or_empty(): void
    {
        Http::fake(); // any request at all would fail preventStrayRequests anyway

        $client = app(SentryApiClient::class);

        $unconfigured = $client->trendFor('some-project');
        $this->assertFalse($unconfigured['configured']);

        $this->configureSentry();
        $this->assertFalse($client->trendFor('')['configured'], 'An empty slug must not produce a network call.');
        $this->assertFalse($client->trendFor('   ')['configured'], 'A whitespace slug must not produce a network call.');
    }

    public function test_trend_for_failures_are_cached_too(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake([
            'sentry.test/*' => Http::response(['detail' => 'nope'], 403),
        ]);

        $client = app(SentryApiClient::class);
        $first = $client->trendFor('denied-project');
        $this->assertArrayHasKey('error', $first);

        // Cached failure: no second request.
        $second = $client->trendFor('denied-project');
        $this->assertArrayHasKey('error', $second);
        Http::assertSentCount(1, 'A failing project must not hammer Sentry on every page load.');
    }

    // ── 4. The Applications page ────────────────────────────────────────

    public function test_applications_page_renders_a_trend_for_a_mapped_app(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake([
            'sentry.test/*' => Http::response([
                'data' => [
                    [now()->timestamp - 3600, ['count' => 6]],
                    [now()->timestamp, ['count' => 3]],
                ],
            ]),
        ]);

        $mapped = $this->app(['name' => 'Mapped App', 'sentry_project_slug' => 'exospace-production']);
        $unmapped = $this->app(['name' => 'Unmapped App']);

        $this->asTier('viewer')->get(route('ops.applications'))
            ->assertOk()
            ->assertSee('Mapped App', false)
            // The mapped app's total (9 events) renders as its cell total.
            ->assertSee('9', false)
            // The unmapped app gets the honest muted state (the tooltip
            // points at the mapping panel), not an error and not a fake
            // trend.
            ->assertSee('Map this application to a Sentry project below', false);
    }

    public function test_applications_page_shows_api_error_for_a_failing_project(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake([
            'sentry.test/*' => Http::response(['detail' => 'denied'], 403),
        ]);

        $this->app(['name' => 'Failing Mapped App', 'sentry_project_slug' => 'denied-project']);

        $this->asTier('viewer')->get(route('ops.applications'))
            ->assertOk()
            ->assertSee('API error', false);
    }

    public function test_applications_page_renders_muted_state_when_sentry_is_unconfigured(): void
    {
        $this->app(['name' => 'Mapped But Tokenless', 'sentry_project_slug' => 'some-project']);

        $response = $this->asTier('viewer')->get(route('ops.applications'));

        // The cell must say WHY it is empty — a mapped app with no API
        // token is NOT a zero-error day. (The page footer legitimately
        // mentions the words “API error” in its legend, so the assertion
        // targets the cell's own tooltip instead.)
        $response->assertOk()
            ->assertSee('Sentry (24 h)', false)
            ->assertSee('no trend until it is set', false);
    }

    public function test_mapping_panel_renders_for_super_admins_only(): void
    {
        $this->app(['name' => 'Panel Target']);

        $super = $this->asMfaSuperAdmin()->get(route('ops.applications'));
        $super->assertOk()
            ->assertSee('Sentry project mapping', false)
            ->assertSee('ops.sentry.mapping', false);

        $viewer = $this->asTier('viewer')->get(route('ops.applications'));
        $viewer->assertOk()
            ->assertDontSee('Sentry project mapping', false)
            ->assertDontSee(route('ops.applications.sentry', 1), false);

        $operator = $this->asTier('operator')->get(route('ops.applications'));
        $operator->assertOk()->assertDontSee('Sentry project mapping', false);
    }

    public function test_mapping_panel_warns_when_the_api_token_is_not_configured(): void
    {
        $this->app();

        $this->asMfaSuperAdmin()->get(route('ops.applications'))
            ->assertOk()
            ->assertSee('API token not configured', false);

        $this->configureSentry();
        Cache::flush();
        Http::fake(['sentry.test/*' => Http::response(['data' => [[now()->timestamp, ['count' => 1]]]])]);

        $this->asMfaSuperAdmin()->get(route('ops.applications'))
            ->assertOk()
            ->assertDontSee('API token not configured', false);
    }

    public function test_the_api_token_never_appears_in_any_page_payload(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake([
            'sentry.test/*' => Http::response(['data' => [[now()->timestamp, ['count' => 1]]]]),
        ]);

        $this->app(['sentry_project_slug' => 'leak-check']);

        $html = $this->asMfaSuperAdmin()->get(route('ops.applications'))->getContent();

        $this->assertStringNotContainsString('test-token-value', $html, 'The API token must never render.');
        Http::assertSent(fn ($request) => ! str_contains((string) $request->url(), 'slack.test'));
    }
}
