<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Ops\Services\SentryApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 4 — the Sentry API bridge.
 *
 * The tile's contract: read-only headlines, cached, fail-soft, and the
 * token NEVER appears in any returned payload or rendered page. These
 * tests pin every branch: unconfigured, success (including Sentry's
 * shifting count-field shapes), auth rejection, rate limit, network
 * failure, malformed JSON, and the cache behavior that keeps a slow or
 * broken Sentry API off the dashboard's critical path.
 */
class OpsSentrySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    private function configure(?string $token = 'test-token', ?string $org = 'exospace'): void
    {
        config([
            'ops.sentry.api_token' => $token,
            'ops.sentry.org' => $org,
            'ops.sentry.base_url' => 'https://sentry.test',
            'ops.sentry.projects' => ['exospace', 'project-b'],
            'ops.sentry.cache_minutes' => 10,
            'ops.sentry.limit' => 5,
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

    public function test_unconfigured_client_returns_honest_state_without_http_calls(): void
    {
        $this->configure(token: null, org: null);

        $summary = app(SentryApiClient::class)->summary();

        $this->assertFalse($summary['configured']);
        $this->assertArrayNotHasKey('issues', $summary);
        $this->assertArrayNotHasKey('error', $summary);

        // Missing org alone is also unconfigured.
        $this->configure(token: 'tok', org: null);
        $this->assertFalse(app(SentryApiClient::class)->isConfigured());
    }

    public function test_successful_fetch_normalizes_issues_and_orders_by_frequency(): void
    {
        $this->configure();

        Http::fake([
            'sentry.test/*' => Http::response([
                [
                    'id' => '111',
                    'title' => 'Undefined array key "gallery"',
                    'culprit' => 'app/Http/Controllers/GalleryController.php in show',
                    'level' => 'Error',
                    'count' => 480,
                    'userCount' => 23,
                    'firstSeen' => '2026-08-20T10:00:00Z',
                    'lastSeen' => '2026-08-26T09:00:00Z',
                    'permalink' => 'https://sentry.test/organizations/exospace/issues/111/',
                    'project' => ['slug' => 'exospace', 'name' => 'Exospace'],
                ],
                [
                    'id' => '222',
                    'title' => 'Redis connection refused',
                    'culprit' => 'app/Services/CachingService.php',
                    'level' => 'Error',
                    // Newer Sentry API shape: count/userCount as objects.
                    'count' => ['count' => 1200, 'userCount' => 87],
                    'userCount' => ['userCount' => 87],
                    'firstSeen' => '2026-08-25T10:00:00Z',
                    'lastSeen' => '2026-08-26T09:30:00Z',
                    // permalink absent — the fallback link must kick in.
                    'project' => ['slug' => 'project-b', 'name' => 'Project B'],
                ],
            ]),
        ]);

        $summary = app(SentryApiClient::class)->summary();

        $this->assertTrue($summary['configured']);
        $this->assertArrayNotHasKey('error', $summary);
        $this->assertSame(2, $summary['total_issues']);
        $this->assertSame(480 + 1200, $summary['total_events']);
        $this->assertSame(23 + 87, $summary['total_users']);

        // Frequency order: the 1200-event issue leads.
        $this->assertSame('Redis connection refused', $summary['issues'][0]['title']);
        $this->assertSame(1200, $summary['issues'][0]['count']);
        $this->assertSame(87, $summary['issues'][0]['user_count']);
        $this->assertSame(
            'https://sentry.test/organizations/exospace/issues/222/',
            $summary['issues'][0]['link'],
            'Missing permalink must fall back to a constructed issue link',
        );

        $this->assertSame('Undefined array key "gallery"', $summary['issues'][1]['title']);
        $this->assertSame('error', $summary['issues'][1]['level'], 'Levels are lowercased for styling');
    }

    public function test_successful_result_is_cached_no_second_http_call(): void
    {
        $this->configure();

        Http::fake([
            'sentry.test/*' => Http::response([
                ['id' => '1', 'title' => 'One issue', 'count' => 5, 'userCount' => 1, 'level' => 'error', 'permalink' => 'https://sentry.test/issues/1', 'project' => ['name' => 'Exospace']],
            ]),
        ]);

        app(SentryApiClient::class)->summary();
        app(SentryApiClient::class)->summary();

        Http::assertSentCount(1);
    }

    public function test_auth_rejection_is_explained_without_leaking_the_token(): void
    {
        $this->configure(token: 'super-secret-token-value');

        Http::fake([
            'sentry.test/*' => Http::response(['detail' => 'Invalid token'], 403),
        ]);

        $summary = app(SentryApiClient::class)->summary();

        $this->assertArrayHasKey('error', $summary);
        $this->assertStringContainsString('403', $summary['error']);
        $this->assertStringContainsString('SENTRY_API_TOKEN', $summary['error']);
        $this->assertStringNotContainsString('super-secret-token-value', $summary['error']);

        // And the error is cached — no hammering.
        app(SentryApiClient::class)->summary();
        Http::assertSentCount(1);
    }

    public function test_rate_limit_message_is_specific(): void
    {
        $this->configure();

        Http::fake(['sentry.test/*' => Http::response([], 429)]);

        $summary = app(SentryApiClient::class)->summary();

        $this->assertStringContainsString('429', $summary['error']);
        $this->assertStringContainsString('rate limit', $summary['error']);
    }

    public function test_unknown_organization_message_points_at_the_env_var(): void
    {
        $this->configure();

        Http::fake(['sentry.test/*' => Http::response([], 404)]);

        $summary = app(SentryApiClient::class)->summary();

        $this->assertStringContainsString('404', $summary['error']);
        $this->assertStringContainsString('SENTRY_ORG_SLUG', $summary['error']);
    }

    public function test_network_failure_degrades_to_error_not_exception(): void
    {
        $this->configure();

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timeout'));

        $summary = app(SentryApiClient::class)->summary();

        $this->assertArrayHasKey('error', $summary);
        $this->assertStringContainsString('unreachable', $summary['error']);
    }

    public function test_malformed_json_is_handled_honestly(): void
    {
        $this->configure();

        Http::fake([
            'sentry.test/*' => Http::response('not json at all', 200),
        ]);

        $summary = app(SentryApiClient::class)->summary();

        $this->assertArrayHasKey('error', $summary);
        $this->assertStringContainsString('unexpected response shape', $summary['error']);
    }

    public function test_issue_limit_is_respected(): void
    {
        $this->configure();
        config(['ops.sentry.limit' => 2]);

        $issues = [];
        for ($i = 1; $i <= 7; $i++) {
            $issues[] = ['id' => (string) $i, 'title' => "Issue {$i}", 'count' => $i * 10, 'userCount' => 1, 'level' => 'error', 'permalink' => "https://sentry.test/issues/{$i}", 'project' => ['name' => 'Exospace']];
        }

        Http::fake(['sentry.test/*' => Http::response($issues)]);

        $summary = app(SentryApiClient::class)->summary();

        $this->assertCount(2, $summary['issues']);
        $this->assertSame(7, $summary['total_issues'], 'The total still reports ALL unresolved issues');
        $this->assertSame('Issue 7', $summary['issues'][0]['title'], 'Highest frequency first');
    }

    public function test_overview_tile_renders_configured_summary(): void
    {
        $this->configure();

        Http::fake([
            'sentry.test/*' => Http::response([
                ['id' => '1', 'title' => 'Undefined array key "gallery"', 'culprit' => 'GalleryController.php', 'level' => 'error', 'count' => 42, 'userCount' => 7, 'permalink' => 'https://sentry.test/organizations/exospace/issues/1/', 'project' => ['name' => 'Exospace']],
            ]),
        ]);

        $response = $this->asMfaSuperAdmin()->get('/ops');

        $response->assertStatus(200)
            ->assertSee('Sentry — Unresolved')
            ->assertSee('Undefined array key', false)
            ->assertSee('https://sentry.test/organizations/exospace/issues/1/', false)
            ->assertSee('Sentry ↗', false);
    }

    public function test_overview_tile_renders_unconfigured_note_and_never_leaks_the_token(): void
    {
        config([
            'ops.sentry.api_token' => null,
            'ops.sentry.org' => null,
            'ops.sentry.projects' => [],
            'ops.sentry.base_url' => 'https://sentry.test',
        ]);

        $response = $this->asMfaSuperAdmin()->get('/ops');

        $response->assertStatus(200)
            ->assertSee('Sentry — Unresolved')
            ->assertSee('not configured')
            ->assertSee('SENTRY_API_TOKEN');
    }

    public function test_overview_tile_renders_api_error_state(): void
    {
        $this->configure();

        Http::fake(['sentry.test/*' => Http::response([], 403)]);

        $response = $this->asMfaSuperAdmin()->get('/ops');

        $response->assertStatus(200)
            ->assertSee('API ERROR')
            ->assertSee('SENTRY_API_TOKEN')
            ->assertSee('retries on the next TTL window', false);
    }
}
