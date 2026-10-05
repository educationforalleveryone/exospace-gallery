<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Models\QaTestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QaRunProfileProbeTest extends TestCase
{
    use RefreshDatabase;

    private array $artifactSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artifactSnapshot = array_merge(
            glob(storage_path('app/private/control-center/*/*.xml')) ?: [],
            glob(storage_path('framework/qa/*.xml')) ?: [],
        );
    }

    protected function tearDown(): void
    {
        foreach (array_merge(
            glob(storage_path('app/private/control-center/*/*.xml')) ?: [],
            glob(storage_path('framework/qa/*.xml')) ?: [],
        ) as $file) {
            if (! in_array($file, $this->artifactSnapshot, true)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_cli_smoke_probe_hits_the_requested_environment_and_records_it(): void
    {
        // Regression pin: the probe strategy used to forward the target as the
        // global --env (APP_ENV only), leaving qa:smoke's --target-env at its
        // production default — a staging run recorded "staging" while probing
        // PRODUCTION. The target must reach qa:smoke via --target-env.
        config()->set('test-center.environments.staging.base_url', 'https://staging.exospace.test');

        Http::fake([
            'staging.exospace.test/up' => Http::response('ok', 200),
            'staging.exospace.test/health' => Http::response(json_encode(['checks' => ['db' => ['status' => 'ok']]]), 200),
            'staging.exospace.test/robots.txt' => Http::response("User-agent: *\nSitemap: https://staging.exospace.test/sitemap.xml", 200),
            'staging.exospace.test/sitemap.xml' => Http::response('<?xml version="1.0"?><urlset/>', 200),
            'staging.exospace.test/login' => Http::response('<form>', 200),
            'staging.exospace.test/register' => Http::response('<form>', 200),
            'staging.exospace.test/' => Http::response('<html><script src="/build/assets/app-X.js"></script></html>', 200),
            '*' => Http::response('', 500), // any wrong-environment probe fails loudly
        ]);

        // Artisan::call (not $this->artisan()) — PendingCommand replaces the
        // console output binding, which empties the nested Artisan::output()
        // buffer that executeProbeStrategy parses. The CLI path (what ships)
        // uses the plain buffering verified here.
        $exit = \Artisan::call('qa:run', ['profile' => 'smoke', '--target' => 'staging']);

        $this->assertSame(0, $exit, 'all 7 smoke checks must pass against staging');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://staging.exospace.test'));

        $run = QaTestRun::query()->where('profile', 'smoke')->latest('id')->first();

        $this->assertNotNull($run, 'the probe must be recorded as a run');
        $this->assertSame('staging', $run->environment);
        $this->assertSame('passed', $run->status);
        $this->assertSame(7, $run->total);
        $this->assertGreaterThan(0, $run->duration_ms ?? 0);
    }
}
