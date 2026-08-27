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

    // ── The error trend (Iteration 6) ────────────────────────────────────

    public function test_trend_is_unconfigured_without_http_calls(): void
    {
        $this->configure(token: null, org: null);

        $trend = app(SentryApiClient::class)->trend();

        $this->assertFalse($trend['configured']);
        $this->assertArrayNotHasKey('series', $trend);
        Http::assertNothingSent();
    }

    public function test_trend_normalizes_the_pair_shape_and_computes_totals(): void
    {
        $this->configure();

        // The documented [unix_ts, {count: N}] shape.
        $ts = time() - 23 * 3600;
        $data = [];
        for ($i = 0; $i < 24; $i++) {
            $data[] = [$ts + $i * 3600, ['count' => $i === 12 ? 40 : 5]];
        }

        Http::fake(['sentry.test/*' => Http::response(['data' => $data])]);

        $trend = app(SentryApiClient::class)->trend();

        $this->assertArrayNotHasKey('error', $trend);
        $this->assertSame(24, $trend['points']);
        $this->assertSame(23 * 5 + 40, $trend['total']);
        $this->assertSame(40, $trend['peak']);
        $this->assertStringContainsString('UTC', $trend['peak_hour']);

        // Chronological: the first series point is the OLDEST bucket.
        $this->assertSame($ts, $trend['series'][0]['ts']);
    }

    public function test_trend_handles_the_object_shape_and_out_of_order_data(): void
    {
        $this->configure();

        // The {time, count} shape, deliberately shuffled.
        $a = ['time' => time() - 2 * 3600, 'count' => 7];
        $b = ['time' => time() - 3 * 3600, 'count' => 9];
        $c = ['time' => time() - 1 * 3600, 'count' => 2];

        Http::fake(['sentry.test/*' => Http::response(['data' => [$a, $b, $c]])]);

        $trend = app(SentryApiClient::class)->trend();

        $this->assertArrayNotHasKey('error', $trend);
        $this->assertSame(3, $trend['points']);
        $this->assertSame(18, $trend['total']);

        // Sorted chronologically regardless of arrival order.
        $this->assertSame($b['time'], $trend['series'][0]['ts']);
        $this->assertSame($c['time'], $trend['series'][2]['ts']);
        $this->assertSame(9, $trend['peak']);
    }

    public function test_trend_handles_plain_int_counts_and_zero_buckets(): void
    {
        $this->configure();

        Http::fake(['sentry.test/*' => Http::response([
            'data' => [
                [time() - 7200, 12],   // plain int shape
                [time() - 3600, 0],    // a quiet hour — still a point
            ],
        ])]);

        $trend = app(SentryApiClient::class)->trend();

        $this->assertArrayNotHasKey('error', $trend);
        $this->assertSame(2, $trend['points']);
        $this->assertSame(12, $trend['total']);
        $this->assertSame(0, $trend['series'][1]['count']);
    }

    public function test_trend_errors_are_structured_and_cached(): void
    {
        $this->configure(token: 'super-secret-token-value');

        Http::fake(['sentry.test/*' => Http::response([], 403)]);

        $trend = app(SentryApiClient::class)->trend();

        // The event:read scope hint rides the message; the token never does.
        $this->assertArrayHasKey('error', $trend);
        $this->assertStringContainsString('event:read', $trend['error']);
        $this->assertStringNotContainsString('super-secret-token-value', json_encode($trend));
        $this->assertArrayNotHasKey('series', $trend);

        // Cached failure — the next call does not hit the API again.
        app(SentryApiClient::class)->trend();
        Http::assertSentCount(1);
    }

    public function test_trend_is_cached_under_its_own_key_separate_from_the_summary(): void
    {
        $this->configure();

        Http::fake(['sentry.test/*' => Http::response([
            'data' => [[time() - 3600, ['count' => 3]]],
        ])]);

        $client = app(SentryApiClient::class);
        $client->trend();
        $client->trend();
        $client->summary(); // different endpoint, different cache key

        // One events-stats call + one issues call — never more.
        $stats = 0;
        $issues = 0;
        foreach (Http::recorded() as [$request]) {
            str_contains((string) $request->url(), 'events-stats') ? $stats++ : $issues++;
        }
        $this->assertSame(1, $stats);
        $this->assertSame(1, $issues);
    }

    public function test_trend_with_no_usable_points_reports_honestly(): void
    {
        $this->configure();

        Http::fake(['sentry.test/*' => Http::response(['data' => 'not-an-array'])]);

        $trend = app(SentryApiClient::class)->trend();

        $this->assertArrayHasKey('error', $trend);
        $this->assertArrayNotHasKey('series', $trend);
    }

    public function test_overview_renders_the_trend_sparkline_as_pure_svg(): void
    {
        $this->configure();

        $data = [];
        $ts = time() - 23 * 3600;
        for ($i = 0; $i < 24; $i++) {
            $data[] = [$ts + $i * 3600, ['count' => $i === 5 ? 30 : 4]];
        }

        Http::fake(['sentry.test/*' => Http::response(['data' => $data])]);

        $content = $this->asMfaSuperAdmin()->get('/ops')->getContent();

        // Pure SVG, no JS: the viewBox is there, 24 bars render, the peak
        // is highlighted and the caption quantifies the day.
        $this->assertStringContainsString('viewBox="0 0 120 36"', $content);
        $this->assertSame(24, substr_count($content, '<rect '));
        $this->assertStringContainsString('fill-amber-400/90', $content);
        $this->assertStringContainsString('Error volume — last 24 h', $content);
        $this->assertStringContainsString('events', $content);
        $this->assertStringContainsString('peak 30/h', $content);
        // And no script tag snuck in.
        $this->assertStringNotContainsString('<script', $content);
    }

    public function test_overview_renders_trend_unavailable_note_when_stats_endpoint_fails(): void
    {
        $this->configure();

        Http::fake(function ($request) {
            // Headlines succeed, the stats endpoint 403s (scope missing).
            return str_contains((string) $request->url(), 'events-stats')
                ? Http::response([], 403)
                : Http::response([
                    ['id' => '1', 'title' => 'One issue', 'count' => 5, 'userCount' => 1, 'level' => 'error', 'permalink' => 'https://sentry.test/issues/1', 'project' => ['name' => 'Exospace']],
                ]);
        });

        $content = $this->asMfaSuperAdmin()->get('/ops')->getContent();

        // The headlines still render (their cache is NOT poisoned)...
        $this->assertStringContainsString('One issue', $content);
        // ...the sparkline does not, and the note says why.
        $this->assertStringContainsString('Error trend unavailable', $content);
        $this->assertStringNotContainsString('viewBox="0 0 120 36"', $content);
    }

    public function test_overview_renders_no_trend_at_all_when_sentry_is_unconfigured(): void
    {
        $this->configure(token: null, org: null);

        $content = $this->asMfaSuperAdmin()->get('/ops')->getContent();

        $this->assertStringNotContainsString('viewBox="0 0 120 36"', $content);
        $this->assertStringNotContainsString('Error trend unavailable', $content);
    }
}
