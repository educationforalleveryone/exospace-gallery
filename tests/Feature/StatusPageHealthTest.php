<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class StatusPageHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The rendered page is cached for 60s — start every test cold.
        Cache::forget('status:page');
    }

    public function test_status_page_reports_every_dependency_operational_when_healthy(): void
    {
        $response = $this->get('/status');

        $response->assertOk();

        $content = $response->getContent();

        // The four checked subsystems plus the "All Systems Operational"
        // banner must read operational — a broken facade import or a
        // false-positive check would render Down/Degraded and publish a
        // false outage on the public status page.
        $this->assertSame(
            5,
            substr_count($content, 'Operational'),
            'database, cache, queue and storage must all report operational',
        );
        $this->assertStringNotContainsString('Down', $content);
        $this->assertStringNotContainsString('Degraded', $content);
    }

    public function test_status_page_reports_cache_down_when_the_cache_store_fails(): void
    {
        $this->app['config']->set('cache.stores.failing', ['driver' => 'failing']);
        $this->app['cache']->extend('failing', function () {
            return $this->app['cache']->repository(new class extends ArrayStore
            {
                public function put($key, $value, $seconds): bool
                {
                    throw new RuntimeException('cache store unreachable');
                }
            });
        });
        config(['cache.default' => 'failing']);

        $response = $this->get('/status');

        $response->assertOk();

        // A cache outage must be visible, never silently swallowed into an
        // all-green page.
        $content = $response->getContent();
        $this->assertStringContainsString('Down', $content);
        $this->assertSame(3, substr_count($content, 'Operational'), 'database, queue and storage stay operational');
    }
}
