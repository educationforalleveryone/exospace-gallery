<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CacheTagService;
use App\Support\SitemapVersion;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RedisOutageDegradationTest extends TestCase
{
    use RefreshDatabase;

    // ── Maintenance gate ────────────────────────────────────────────────

    public function test_requests_continue_when_the_maintenance_store_is_unreachable(): void
    {
        $this->app->bind(MaintenanceModeContract::class, function () {
            return new class implements MaintenanceModeContract
            {
                public function activate(array $payload): void {}

                public function deactivate(): void {}

                public function active(): bool
                {
                    throw new RuntimeException('Connection refused [tcp://redis:6379]');
                }

                public function data(): array
                {
                    return [];
                }
            };
        });

        // The gate must fail open: an unreachable maintenance store is a
        // Redis outage symptom, not a reason to 500 the whole application.
        $this->get('/health')->assertOk();
    }

    public function test_real_maintenance_mode_still_blocks_requests(): void
    {
        $this->app->bind(MaintenanceModeContract::class, function () {
            return new class implements MaintenanceModeContract
            {
                public function activate(array $payload): void {}

                public function deactivate(): void {}

                public function active(): bool
                {
                    return true;
                }

                public function data(): array
                {
                    return [];
                }
            };
        });

        $this->get('/health')->assertStatus(503);
    }

    // ── Tagged cache reads ──────────────────────────────────────────────

    private function bindTagCapableStore(): void
    {
        // A RedisStore-shaped store: supportsTags() is true, but the
        // underlying connection is never reached because tags() throws.
        $store = new class(Mockery::mock(\Illuminate\Contracts\Redis\Factory::class)) extends RedisStore {};

        Cache::shouldReceive('getStore')->andReturn($store);
    }

    public function test_tagged_remember_serves_uncached_when_the_store_fails(): void
    {
        $this->bindTagCapableStore();
        Cache::shouldReceive('tags')->andThrow(new RuntimeException('READONLY Redis is in read-only mode'));

        $calls = 0;
        $result = app(CacheTagService::class)->rememberTagged(
            ['analytics', 'analytics:gallery:1'],
            'analytics:top-artworks:1',
            now()->addMinutes(10),
            function () use (&$calls) {
                $calls++;

                return 'live-value';
            },
        );

        $this->assertSame('live-value', $result);
        $this->assertSame(1, $calls, 'The value must come from the callback, not a broken store.');
    }

    public function test_tagged_flexible_serves_uncached_when_the_store_fails(): void
    {
        $this->bindTagCapableStore();
        Cache::shouldReceive('tags')->andThrow(new RuntimeException('Connection refused'));

        $calls = 0;
        $result = app(CacheTagService::class)->flexibleTagged(
            ['analytics'],
            'analytics:referrers:1',
            [now()->addMinutes(10), now()->addMinutes(15)],
            function () use (&$calls) {
                $calls++;

                return 'live-flexible';
            },
        );

        $this->assertSame('live-flexible', $result);
        $this->assertSame(1, $calls);
    }

    public function test_tagged_invalidation_swallow_store_failure_without_throwing(): void
    {
        $this->bindTagCapableStore();
        Cache::shouldReceive('tags')->andThrow(new RuntimeException('Connection refused'));

        // Must not throw: the flush will be retried on the next mutation and
        // entry TTLs bound the staleness in the meantime.
        app(CacheTagService::class)->invalidateTag('analytics');
        app(CacheTagService::class)->invalidateTags(['og', 'sitemap']);

        $this->assertInstanceOf(CacheTagService::class, app(CacheTagService::class));
    }

    public function test_untagged_fallback_degrades_through_resilient_cache(): void
    {
        Cache::shouldReceive('getStore')->andReturn(new ArrayStore);
        Cache::shouldReceive('get')->andThrow(new RuntimeException('Redis down'));
        Cache::shouldReceive('put')->andThrow(new RuntimeException('Redis down'));
        Cache::shouldReceive('remember')->andThrow(new RuntimeException('Redis down'));

        $result = app(CacheTagService::class)->rememberTagged(
            ['analytics'],
            'analytics:top-artworks:2',
            now()->addMinutes(10),
            fn () => 'uncached-live',
        );

        $this->assertSame('uncached-live', $result);
    }

    public function test_remember_tagged_still_caches_on_a_healthy_store(): void
    {
        $service = app(CacheTagService::class);

        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return 'computed-once';
        };

        $first = $service->rememberTagged(['test-tag'], 'outage-test:healthy', now()->addMinutes(5), $resolver);
        $second = $service->rememberTagged(['test-tag'], 'outage-test:healthy', now()->addMinutes(5), $resolver);

        $this->assertSame('computed-once', $first);
        $this->assertSame('computed-once', $second);
        $this->assertSame(1, $calls, 'A healthy store must serve the second read from cache.');
    }

    public function test_version_stamp_reads_still_work_after_tagged_cache_changes(): void
    {
        // Guard against accidental breakage of the stamped-key rotation the
        // public pages rely on.
        $before = (int) SitemapVersion::version();
        SitemapVersion::bump();

        $this->assertSame($before + 1, (int) SitemapVersion::version());
    }
}
