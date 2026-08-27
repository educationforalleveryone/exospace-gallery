<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Models\QaTestCaseResult;
use App\Models\QaTestRun;
use App\Services\TestCenter\FlakyDetector;
use App\Services\TestCenter\QaNotifier;
use App\Services\TestCenter\ReleaseReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Iteration 3 self-verification: the DECISION layer.
 *
 * Honest-release contract tested here:
 *  - missing / stale / failing blocking gate ⇒ BLOCKED with actionable reasons
 *  - advisory failure ⇒ warning that never blocks
 *  - flaky detection distinguishes alternation from perma-red
 *  - smoke probes assert only publicly observable truth (Http::fake-able)
 *  - notifications route through OperationalAlertService once per dedup window
 */
class ReleaseIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    /* ── Release readiness ─────────────────────────────────────────────── */

    public function test_fresh_all_green_blocking_and_advisory_is_ready(): void
    {
        foreach (['pre_release', 'security', 'billing', 'seo', 'database', 'smoke'] as $profile) {
            QaTestRun::factory()->create([
                'profile'    => $profile,
                'status'     => 'passed',
                'total'      => 100,
                'passed'     => 100,
                'created_at' => now()->subHours(2),
            ]);
        }
        // ci_build synthetic gate — any ci-triggered artifact inside freshness counts.
        QaTestRun::factory()->create(['trigger' => 'ci', 'created_at' => now()->subHour()]);

        $result = app(ReleaseReadinessService::class)->evaluate('production');

        $this->assertSame('ready', $result['verdict'], json_encode($result['summary']));
        $this->assertSame(0, $result['summary']['blocking']);
        $this->assertSame(7, $result['summary']['total_gates']);
        $this->assertSame('green', $result['gates']['security']['verdict']);
    }

    public function test_advisory_gate_failure_never_blocks_the_ship(): void
    {
        foreach (['pre_release', 'security', 'billing', 'smoke'] as $profile) {
            QaTestRun::factory()->create([
                'profile' => $profile, 'status' => 'passed',
                'total' => 100, 'passed' => 100,
                'created_at' => now()->subHours(2),
            ]);
        }
        QaTestRun::factory()->create(['trigger' => 'ci', 'created_at' => now()->subHour()]);
        // Advisory gates red or ancient — warning only:
        QaTestRun::factory()->create(['profile' => 'seo', 'status' => 'failed']);
        QaTestRun::factory()->create(['profile' => 'database', 'status' => 'failed', 'created_at' => now()->subDays(3)]);

        $result = app(ReleaseReadinessService::class)->evaluate('production');

        $this->assertSame('ready-with-warnings', $result['verdict']);
        $this->assertSame(2, $result['summary']['advisory_failing']);
        $this->assertSame(5, $result['summary']['passing']); // 4 suite gates + build gate satisfied
    }

    public function test_missing_blocking_run_blocks_release_with_actionable_reasons(): void
    {
        QaTestRun::factory()->create(['profile' => 'smoke', 'status' => 'passed']);

        $result = app(ReleaseReadinessService::class)->evaluate('production');

        $this->assertSame('blocked', $result['verdict']);
        $this->assertGreaterThan(0, $result['summary']['blocking']);

        $reasons = implode("\n", $result['summary']['reasons']);
        $this->assertStringContainsString('[Pre-Release suite]', $reasons);
        $this->assertStringContainsString('Never executed', $reasons);
    }

    public function test_stale_run_beyond_freshness_requires_rerun_to_reprove(): void
    {
        QaTestRun::factory()->create([
            'profile'    => 'pre_release',
            'status'     => 'passed',
            'created_at' => now()->subHours(72),
        ]);
        QaTestRun::factory()->create(['profile' => 'smoke', 'status' => 'passed']);

        $result = app(ReleaseReadinessService::class)->evaluate('production');
        $gate   = $result['gates']->get('tests');

        $this->assertSame('stale', $gate['state']);
        $this->assertStringContainsString('Re-run to re-prove', $gate['note']);
        $this->assertContains($result['verdict'], ['blocked', 'unproven']);
    }

    /* ── Flaky detector semantics ──────────────────────────────────────── */

    public function test_alternating_test_flagged_flaky_and_failing_tail_separately(): void
    {
        $flakyId = 'Tests\Feature\WebhookDeliveryLedgerTest::test_retry_exhausted';
        // F P P F pattern → alternation with both outcomes present.
        foreach ([false, true, true, false] as $green) {
            $run = QaTestRun::factory()->create(['profile' => 'webhooks', 'status' => $green ? 'passed' : 'failed']);
            $this->seedCase($run->id, $flakyId, $green);
        }

        $row = (new FlakyDetector(recentRuns: 20, minExecutions: 4))
            ->detect('webhooks')
            ->firstWhere('test_identifier', $flakyId);

        $this->assertNotNull($row, 'alternating case must be detected');
        $this->assertSame('flaky', $row['kind']);
        $this->assertSame(50.0, $row['pass_rate']);

        // Recently-broken tail: green history then a stuck failure run.
        $brokenId = 'Tests\Feature\SitemapWarmTest::test_toggles';
        foreach ([true, true, true, true, false, false] as $idx => $green) {
            $run = QaTestRun::factory()->create(['profile' => 'seo', 'status' => $green ? 'passed' : 'failed']);
            $this->seedCase($run->id, $brokenId, $green);
        }
        $tail = app(FlakyDetector::class)->detect('seo')->firstWhere('test_identifier', $brokenId);

        $this->assertNotNull($tail);
        $this->assertSame(66.7, $tail['pass_rate']); // 4 green of 6 executions
        $this->assertNotSame('perma-red', $tail['kind'], 'had green history — must not read as permanent');
    }

    private function seedCase(int $runId, string $identifier, bool $green): void
    {
        [$class, $method] = explode('::', $identifier);

        QaTestCaseResult::query()->create([
            'qa_test_run_id'  => $runId,
            'test_identifier' => $identifier,
            'classname'       => $class,
            'method_name'     => $method,
            'status'          => $green ? 'passed' : 'failed',
            'message'         => $green ? null : 'synthetic boom',
        ]);
    }

    /* ── Smoke executor ────────────────────────────────────────────────── */

    public function test_smoke_verifies_deployed_surface_end_to_end(): void
    {
        Http::fake([
            'https://exospace.test/up'          => Http::response('ok', 200),
            'https://exospace.test/health'      => Http::response(json_encode(['checks' => ['db' => ['status' => 'ok']]]), 200),
            'https://exospace.test/robots.txt'  => Http::response("User-agent: *\nAllow: /\nSitemap: https://exospace.test/sitemap.xml", 200),
            'https://exospace.test/sitemap.xml' => Http::response('<?xml version="1.0"?><urlset/>', 200),
            'https://exospace.test/login'       => Http::response('<form>', 200),
            'https://exospace.test/register'    => Http::response('<form>', 200),
            'https://exospace.test/'            => Http::response('<html><script src="/build/assets/app-X.js"></script></html>', 200),
        ]);

        \Artisan::call('qa:smoke', ['--target' => 'https://exospace.test', '--format' => 'junit-json']);
        $json = json_decode(\Artisan::output(), true);

        $this->assertSame(7, $json['totals']['tests']);
        $this->assertSame(0, $json['totals']['failures']);

        $names = array_column($json['cases'], 'name');
        $this->assertContains('/up', $names);
        $this->assertContains('build-assets', $names);
        $this->assertContains('robots.txt', $names);

        $upCase = $json['cases'][array_search('/up', $names, true)];
        $this->assertSame('passed', $upCase['status']);
    }

    public function test_smoke_refuses_green_when_the_site_is_down(): void
    {
        Http::fake(fn () => Http::response('', 500));

        \Artisan::call('qa:smoke', ['--target' => 'https://down.test', '--format' => 'junit-json']);
        $json = json_decode(\Artisan::output(), true);

        $this->assertGreaterThan(0, $json['totals']['failures']);
    }

    /* ── Dashboard smoke execution (Iteration 3 close-out) ─────────────── */

    public function test_dashboard_start_of_smoke_executes_inline_and_records_run(): void
    {
        config()->set('test-center.admin_emails', ['qa@exospace.gallery']);
        // Pin the non-production target so the controller resolves a real URL.
        config()->set('test-center.environments.staging.base_url', 'https://staging.exospace.test');
        $user = \App\Models\User::factory()->create(['email' => 'qa@exospace.gallery']);

        Http::fake([
            'https://staging.exospace.test/up'          => Http::response('ok', 200),
            'https://staging.exospace.test/health'      => Http::response(json_encode(['checks' => ['db' => ['status' => 'ok']]]), 200),
            'https://staging.exospace.test/robots.txt'  => Http::response("User-agent: *\nAllow: /\nSitemap: https://staging.exospace.test/sitemap.xml", 200),
            'https://staging.exospace.test/sitemap.xml' => Http::response('<?xml version="1.0"?><urlset/>', 200),
            'https://staging.exospace.test/login'       => Http::response('<form>', 200),
            'https://staging.exospace.test/register'    => Http::response('<form>', 200),
            'https://staging.exospace.test/'            => Http::response('<html><script src="/build/assets/app-X.js"></script></html>', 200),
        ]);

        $response = $this->actingAs($user)->post('/control-center/profiles/smoke/start');

        $run = QaTestRun::query()->where('profile', 'smoke')->latest('id')->first();

        $this->assertNotNull($run, 'a run row must be recorded');
        $this->assertSame('passed', $run->status);
        $this->assertGreaterThan(0, $run->total);
        $response->assertRedirect(route('control-center.run.show', ['run' => $run]));
    }

    /* ── Notifications ─────────────────────────────────────────────────── */

    public function test_notifier_routes_failure_through_operational_alert_service_with_dedup_key(): void
    {
        Http::fake(); // swallow any real posts

        $persisted = QaTestRun::factory()->create([
            'profile'       => 'billing',
            'environment'   => 'ci',
            'status'        => 'failed',
            'failure_class' => 'application',
            'git_commit'    => str_repeat('f', 40),
        ]);

        $alerts = \Mockery::mock(\App\Services\OperationalAlertService::class)->makePartial();
        $alerts->shouldReceive('alert')->once()->withArgs(function ($title, $msg, $severity, $dedup) use ($persisted) {
            return str_contains((string) $title, '[QA]')
                && str_contains((string) $title, 'billing FAILED')
                && $severity === 'critical'
                && str_starts_with((string) $dedup, 'qa_run_failed:billing:')
                && str_contains((string) $msg, substr(str_repeat('f', 40), 0, 10));
        });

        $this->instance(\App\Services\OperationalAlertService::class, $alerts);
        app(QaNotifier::class, ['alerts' => $alerts])->runFailed($persisted);

        $this->assertTrue(true); // expectation enforced via shouldReceive(once)
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
