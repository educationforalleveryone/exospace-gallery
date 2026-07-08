<?php

declare(strict_types=1);

/**
 * Iteration-001: TRUSTED_PROXIES hard-fail regression test (audit CR-5).
 *
 * Verifies that AppServiceProvider::boot() throws a RuntimeException when
 * TRUSTED_PROXIES is empty or '*' in production, and does NOT throw in
 * non-production environments.
 *
 * Run: php artisan test --filter=TrustedProxiesTest
 */

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    public function test_cr5_throws_in_production_when_trusted_proxies_is_star(): void
    {
        $this->expectTrustedProxiesRuntimeException('*', 'production');
    }

    public function test_cr5_throws_in_production_when_trusted_proxies_is_empty(): void
    {
        $this->expectTrustedProxiesRuntimeException('', 'production');
    }

    public function test_cr5_throws_in_production_when_trusted_proxies_is_null(): void
    {
        $this->expectTrustedProxiesRuntimeException(null, 'production');
    }

    public function test_cr5_does_not_throw_in_production_when_trusted_proxies_is_set(): void
    {
        $this->app['env'] = 'production';
        putenv('TRUSTED_PROXIES=172.16.0.0/12');

        // Reboot the service provider to pick up the new env
        $this->refreshApplication();

        // If we got here without an exception, the test passes
        $this->assertTrue(true, 'CR-5: No exception thrown when TRUSTED_PROXIES is set to a valid subnet.');

        putenv('TRUSTED_PROXIES');
    }

    public function test_cr5_does_not_throw_in_local_when_trusted_proxies_is_star(): void
    {
        $this->app['env'] = 'local';
        putenv('TRUSTED_PROXIES=*');

        // Reboot the service provider
        $this->refreshApplication();

        // In local env, '*' is allowed (with a warning log)
        $this->assertTrue(true, 'CR-5: No exception thrown in local env with TRUSTED_PROXIES=*.');

        putenv('TRUSTED_PROXIES');
    }

    public function test_cr5_does_not_throw_in_testing_env(): void
    {
        // Default testing env — should not throw
        $this->assertTrue(true, 'CR-5: No exception thrown in testing env.');
    }

    private function expectTrustedProxiesRuntimeException(?string $trustedProxies, string $env): void
    {
        $this->app['env'] = $env;
        putenv('TRUSTED_PROXIES=' . ($trustedProxies ?? ''));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/TRUSTED_PROXIES/i');

        // Reboot the service provider to trigger boot()
        $this->refreshApplication();

        putenv('TRUSTED_PROXIES');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Save original env so we can restore in tearDown
        $this->originalEnv = $_ENV['TRUSTED_PROXIES'] ?? null;
        $this->originalAppEnv = $this->app['env'] ?? 'testing';
    }

    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES=' . ($this->originalEnv ?? ''));
        $this->app['env'] = $this->originalAppEnv;
        parent::tearDown();
    }

    private ?string $originalEnv;
    private string $originalAppEnv;
}
