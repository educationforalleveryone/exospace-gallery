<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ops\Models\OpsApplication;
use App\Ops\Services\SentryApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 9, Feature B — per-app Sentry issue headlines.
 *
 * The Iteration-8 trend column answers "HOW MUCH is this app throwing?";
 * the headlines card answers "WHAT is it throwing?" — top unresolved
 * issues by frequency with permalinks, one card per MAPPED app on
 * /ops/applications.
 *
 * Pinned here:
 *   1. summaryFor() — the issues endpoint scoped to ONE project with its
 *      OWN per-project cache key (a failing project must degrade exactly
 *      its own card, never the org summary or a sibling); failures
 *      cached like successes; unconfigured/empty-slug short-circuits
 *      with zero network calls; the token never appears in any payload.
 *   2. fetch(?string $project) refactor — the org-wide summary still
 *      applies the config project filter; the per-app call ignores it.
 *   3. The card's four honest states — not-configured must NOT claim a
 *      zero-error day (the it8 cell-state lesson), API error says why,
 *      the honest zero says the API answered quiet, the list slices to
 *      three and offers the Sentry link for the rest.
 *   4. The section is read-only data → viewer-visible, and hidden
 *      entirely while nothing is mapped.
 */
class OpsSentryHeadlinesTest extends TestCase
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

        // Per-project cache isolation across tests.
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
        $admin = \App\Models\User::factory()->withMfa()->create([
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
        $user = \App\Models\User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        \App\Ops\Models\OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => $level,
            'granted_by' => \App\Models\User::factory()->create(['is_super_admin' => true])->id,
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

    /**
     * A Sentry issues payload in the documented shape: title, culprit,
     * count (int shape), userCount (object shape — both are normalized).
     */
    private function issuesPayload(int $count = 2): array
    {
        $issues = [];
        foreach (range(1, $count) as $i) {
            $issues[] = [
                'id' => (string) (1000 + $i),
                'title' => "Undefined array key 'field-{$i}'",
                'culprit' => "app/Models/Thing-{$i}.php in handle()",
                'level' => 'error',
                'count' => 10 * $i,
                'userCount' => ['userCount' => $i],
                'firstSeen' => now()->subDays(2)->toIso8601String(),
                'lastSeen' => now()->subMinutes($i)->toIso8601String(),
                'permalink' => "https://sentry.test/organizations/test-org/issues/100{$i}/",
                'project' => ['name' => 'Test Project', 'slug' => 'test-project'],
            ];
        }

        return $issues;
    }

    // ── 1. summaryFor(): scoping + caching ──────────────────────────────

    public function test_summary_for_is_unconfigured_without_a_token(): void
    {
        Http::fake(); // any request at all would fail preventStrayRequests anyway

        $result = app(SentryApiClient::class)->summaryFor('some-project');

        $this->assertSame(['configured' => false], $result);
        Http::assertSentCount(0);
    }

    public function test_summary_for_short_circuits_an_empty_slug(): void
    {
        $this->configureSentry();
        Http::fake();

        $this->assertSame(['configured' => false], app(SentryApiClient::class)->summaryFor('   '));
        Http::assertSentCount(0);
    }

    public function test_summary_for_scopes_to_the_project_and_caches_per_project(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake([
            'sentry.test/*' => Http::response($this->issuesPayload(2)),
        ]);

        $client = app(SentryApiClient::class);
        $result = $client->summaryFor('exospace-production');

        $this->assertTrue($result['configured']);
        $this->assertSame(2, $result['total_issues']);
        $this->assertSame(30, $result['total_events']); // 10 + 20
        $this->assertSame(3, $result['total_users']);   // 1 + 2 (object shape normalized)
        $this->assertCount(2, $result['issues']);
        // Frequency-sorted: field-2 (20 events) outranks field-1 (10).
        $this->assertSame("Undefined array key 'field-2'", $result['issues'][0]['title']);
        $this->assertSame("Undefined array key 'field-1'", $result['issues'][1]['title']);

        Http::assertSent(function ($request) {
            if (! str_contains((string) $request->url(), '/issues/')) {
                return false;
            }

            return in_array('exospace-production', (array) ($request->data()['project'] ?? []), true);
        });

        // Second call: served from the per-project cache — no new request.
        $client->summaryFor('exospace-production');
        Http::assertSentCount(1);
    }

    public function test_a_failing_project_does_not_poison_the_org_summary_or_siblings(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, '/issues/') && in_array('broken-project', (array) ($request->data()['project'] ?? []), true)) {
                return Http::response(['detail' => 'not found'], 404);
            }
            if (str_contains($url, '/issues/')) {
                return Http::response($this->issuesPayload(1));
            }

            return Http::response(['data' => [[now()->timestamp, ['count' => 4]]]]);
        });

        $client = app(SentryApiClient::class);

        $broken = $client->summaryFor('broken-project');
        $this->assertArrayHasKey('error', $broken, 'The 404 must surface as an honest error.');
        $this->assertStringContainsString('not found', $broken['error']);

        $sibling = $client->summaryFor('healthy-project');
        $this->assertArrayNotHasKey('error', $sibling, 'A sibling project must not inherit the failure.');
        $this->assertSame(1, $sibling['total_issues']);

        $org = $client->summary();
        $this->assertArrayNotHasKey('error', $org, 'The org-wide summary must not inherit a project failure.');
    }

    public function test_summary_for_failures_are_cached_not_retried(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake([
            'sentry.test/*' => Http::response(['detail' => 'denied'], 403),
        ]);

        $client = app(SentryApiClient::class);

        $first = $client->summaryFor('denied-project');
        $this->assertArrayHasKey('error', $first);

        $second = $client->summaryFor('denied-project');
        $this->assertSame($first, $second, 'The cached failure is served verbatim.');
        Http::assertSentCount(1, 'A broken project must not turn every page load into an API attempt.');
    }

    public function test_org_summary_still_applies_the_config_project_filter(): void
    {
        $this->configureSentry();
        config(['ops.sentry.projects' => ['alpha', 'beta']]);
        Cache::flush();

        Http::fake([
            'sentry.test/*' => Http::response($this->issuesPayload(1)),
        ]);

        app(SentryApiClient::class)->summary();

        Http::assertSent(function ($request) {
            if (! str_contains((string) $request->url(), '/issues/')) {
                return false;
            }
            $projects = (array) ($request->data()['project'] ?? []);

            return in_array('alpha', $projects, true) && in_array('beta', $projects, true);
        });
    }

    public function test_the_token_never_appears_in_any_summary_for_payload(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake([
            'sentry.test/*' => Http::response($this->issuesPayload(1)),
        ]);

        $result = app(SentryApiClient::class)->summaryFor('leak-check');

        $this->assertTrue($result['configured']);
        $this->assertStringNotContainsString('test-token-value', json_encode($result), 'The API token must never ride along in a returned payload.');
    }

    // ── 2. The headlines section on /ops/applications ───────────────────

    public function test_the_page_renders_issue_headline_cards_for_mapped_apps(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake([
            'sentry.test/*' => Http::response($this->issuesPayload(2)),
        ]);

        $this->app(['name' => 'Headline App', 'sentry_project_slug' => 'headline-project']);

        $this->asTier('viewer')->get(route('ops.applications'))
            ->assertOk()
            ->assertSee('Sentry issue headlines', false)
            ->assertSee('Headline App', false)
            ->assertSee('headline-project', false)
            ->assertSee("Undefined array key 'field-2'") // escaped by Blade — assertSee's default escaping matches
            ->assertSee('https://sentry.test/organizations/test-org/issues/1002/', false)
            ->assertSee('2 unresolved issues', false);
    }

    public function test_cards_slice_to_three_and_offer_the_sentry_link_for_the_rest(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake([
            'sentry.test/*' => Http::response($this->issuesPayload(5)),
        ]);

        $this->app(['name' => 'Noisy App', 'sentry_project_slug' => 'noisy-project']);

        $response = $this->asTier('viewer')->get(route('ops.applications'));

        // Frequency-sorted: counts 10..50 → the TOP THREE are field-5,
        // field-4, field-3; field-1/field-2 fall below the fold.
        $response->assertOk()
            ->assertSee('field-5', false)
            ->assertSee('field-4', false)
            ->assertSee('field-3', false)
            ->assertDontSee('field-2', false)
            ->assertDontSee('field-1', false)
            ->assertSee('2 more in Sentry', false);
    }

    public function test_a_quiet_project_renders_the_honest_zero(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake([
            'sentry.test/*' => Http::response([]),
        ]);

        $this->app(['name' => 'Quiet App', 'sentry_project_slug' => 'quiet-project']);

        $this->asTier('viewer')->get(route('ops.applications'))
            ->assertOk()
            ->assertSee('No unresolved issues in the last 24 h.', false);
    }

    public function test_a_failing_project_renders_the_error_state_on_its_own_card(): void
    {
        $this->configureSentry();
        Cache::flush();

        Http::fake(function ($request) {
            if (in_array('broken-project', (array) ($request->data()['project'] ?? []), true)) {
                return Http::response(['detail' => 'not found'], 404);
            }

            return Http::response($this->issuesPayload(1));
        });

        $this->app(['name' => 'Broken Headlines App', 'sentry_project_slug' => 'broken-project']);
        $this->app(['name' => 'Healthy Headlines App', 'sentry_project_slug' => 'healthy-project']);

        $this->asTier('viewer')->get(route('ops.applications'))
            ->assertOk()
            ->assertSee('Issue headlines unavailable', false)
            ->assertSee("Undefined array key 'field-1'", 'The sibling card keeps its data.');
    }

    public function test_an_unconfigured_token_renders_not_fetched_not_a_zero_day(): void
    {
        // Mapped app, but SENTRY_API_TOKEN unset: no fetch was attempted,
        // so the card must NOT claim a zero-error day.
        $this->app(['name' => 'Mapped But Tokenless', 'sentry_project_slug' => 'tokenless-project']);

        $response = $this->asTier('viewer')->get(route('ops.applications'));

        $response->assertOk()
            ->assertSee('Headlines not fetched', false)
            ->assertSee('API token is not configured', false)
            ->assertDontSee('No unresolved issues in the last 24 h.', false);
    }

    public function test_the_section_is_hidden_while_nothing_is_mapped(): void
    {
        $this->configureSentry();
        Cache::flush();
        Http::fake(['sentry.test/*' => Http::response([])]);

        $this->app(['name' => 'Unmapped App']);

        $this->asTier('viewer')->get(route('ops.applications'))
            ->assertOk()
            ->assertDontSee('Sentry issue headlines', false);
    }

    public function test_headlines_are_viewer_visible_and_section_copy_is_stable(): void
    {
        $this->configureSentry();
        Cache::flush();
        Http::fake(['sentry.test/*' => Http::response($this->issuesPayload(1))]);

        $this->app(['name' => 'Viewer Target', 'sentry_project_slug' => 'viewer-project']);

        // Viewer tier: read-only data — the section is theirs too.
        $this->asTier('viewer')->get(route('ops.applications'))
            ->assertOk()
            ->assertSee('Sentry issue headlines (mapped apps, 24 h)', false);

        // Super admin sees it as well (plus the mapping panel).
        $this->asMfaSuperAdmin()->get(route('ops.applications'))
            ->assertOk()
            ->assertSee('Sentry issue headlines (mapped apps, 24 h)', false);
    }
}
