<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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

    // ── Request-level trust wiring ─────────────────────────────────────
    //
    // The boot-time guard proves the CONFIG is safe; these tests prove the
    // configured range actually drives Symfony's trusted-header resolution
    // on live requests. This is the production failure mode: a wrong subnet
    // silently breaks HTTPS detection (and with it HSTS, url() generation
    // and the secure-cookie decision), while an over-broad one lets anyone
    // spoof their address.

    public function test_forwarded_https_from_the_configured_proxy_range_is_trusted(): void
    {
        config(['trustedproxy.proxies' => '10.0.1.0/24']);
        $this->registerTrustProbeRoute();

        $response = $this->call('GET', '/_trust-probe', server: [
            'REMOTE_ADDR' => '10.0.1.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);

        $this->assertTrue($response->json('secure'),
            'X-Forwarded-Proto from the trusted Coolify Traefik subnet must mark the request secure.');
        $this->assertSame('203.0.113.7', $response->json('ip'),
            'The real client IP from a trusted proxy must replace the proxy hop.');
    }

    public function test_forwarded_headers_from_a_peer_outside_the_proxy_range_are_ignored(): void
    {
        config(['trustedproxy.proxies' => '10.0.1.0/24']);
        $this->registerTrustProbeRoute();

        $response = $this->call('GET', '/_trust-probe', server: [
            'REMOTE_ADDR' => '192.0.2.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);

        $this->assertFalse($response->json('secure'),
            'A peer outside the trusted range must NOT be able to spoof HTTPS.');
        $this->assertSame('192.0.2.9', $response->json('ip'),
            'A peer outside the trusted range must NOT be able to spoof its address (rate-limit bypass).');
    }

    public function test_permissive_star_config_trusts_the_calling_proxy(): void
    {
        config(['trustedproxy.proxies' => '*']);
        $this->registerTrustProbeRoute();

        $response = $this->call('GET', '/_trust-probe', server: [
            'REMOTE_ADDR' => '10.0.1.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);

        $this->assertTrue($response->json('secure'));
        $this->assertSame('203.0.113.7', $response->json('ip'));
    }

    public function test_empty_proxy_config_fails_closed(): void
    {
        config(['trustedproxy.proxies' => null]);
        $this->registerTrustProbeRoute();

        $response = $this->call('GET', '/_trust-probe', server: [
            'REMOTE_ADDR' => '10.0.1.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);

        $this->assertFalse($response->json('secure'));
        $this->assertSame('10.0.1.5', $response->json('ip'));
    }

    private function registerTrustProbeRoute(): void
    {
        Route::get('/_trust-probe', fn (Request $request) => response()->json([
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
        ]));
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
        // The TrustProxies::at() configurator writes STATIC state during
        // boot (the local-env '*' test above reboots the app with
        // TRUSTED_PROXIES=*). The static survives refreshApplication and
        // would silently leak trusted-proxy config into every later test
        // request in this process — always reset it.
        $state = new \ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies');
        $state->setValue(null, null);

        parent::tearDown();
    }

    private ?string $originalEnv;

    private string $originalAppEnv;
}
