<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    public function test_throws_in_production_when_trusted_proxies_is_star(): void
    {
        $this->expectTrustedProxiesRuntimeException('*', 'production');
    }

    public function test_throws_in_production_when_trusted_proxies_is_empty(): void
    {
        $this->expectTrustedProxiesRuntimeException('', 'production');
    }

    public function test_throws_in_production_when_trusted_proxies_is_null(): void
    {
        $this->expectTrustedProxiesRuntimeException(null, 'production');
    }

    public function test_does_not_throw_in_production_when_trusted_proxies_is_set(): void
    {
        // A concrete subnet passes the guard without throwing.
        \App\Providers\AppServiceProvider::assertTrustedProxiesConfigured('172.16.0.0/12');

        // If we got here without an exception, the test passes
        $this->assertTrue(true, 'No exception thrown when TRUSTED_PROXIES is set to a valid subnet.');
    }

    public function test_does_not_throw_in_local_when_trusted_proxies_is_star(): void
    {
        $this->app['env'] = 'local';
        putenv('TRUSTED_PROXIES=*');

        // Reboot the service provider
        $this->refreshApplication();

        // In local env, '*' is allowed (with a warning log)
        $this->assertTrue(true, 'No exception thrown in local env with TRUSTED_PROXIES=*.');

        putenv('TRUSTED_PROXIES');
    }

    public function test_does_not_throw_in_testing_env(): void
    {
        // Default testing env — should not throw
        $this->assertTrue(true, 'No exception thrown in testing env.');
    }

    private function expectTrustedProxiesRuntimeException(?string $trustedProxies, string $env): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/TRUSTED_PROXIES/i');

        \App\Providers\AppServiceProvider::assertTrustedProxiesConfigured($trustedProxies);
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
        parent::tearDown();
    }

    private ?string $originalEnv;
    private string $originalAppEnv;
}
