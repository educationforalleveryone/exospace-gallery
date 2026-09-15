<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CspSecurityHeaderRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_csp_keeps_unsafe_eval_required_by_alpine(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp, 'CSP header should be set in non-local environments.');
        $this->assertStringContainsString(
            "'unsafe-eval'",
            $csp,
            'CSP must keep unsafe-eval — Alpine 3.x requires new Function() for x-data expressions.'
        );
    }

    public function test_alpine_import_uses_standard_build(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));

        // The standard import should be present.
        $this->assertStringContainsString(
            "import Alpine from 'alpinejs';",
            $appJs,
            'app.js should use the standard Alpine import (alpinejs).'
        );

        // The broken CSP-safe import should NOT be present.
        $this->assertStringNotContainsString(
            "import Alpine from 'alpinejs/dist/cdn.min.js';",
            $appJs,
            'app.js should NOT import cdn.min.js (no ES module export default — build fails).'
        );
    }

    public function test_security_headers_document_why_unsafe_eval_is_kept(): void
    {
        $source = file_get_contents(app_path('Http/Middleware/SecurityHeaders.php'));

        $this->assertStringContainsString(
            "KEPT 'unsafe-eval'",
            $source,
            'SecurityHeaders should document that unsafe-eval is KEPT (not removed).'
        );
        $this->assertStringContainsString(
            'new Function',
            $source,
            'SecurityHeaders should explain that Alpine 3.x uses new Function for expression evaluation.'
        );
        $this->assertStringContainsString(
            'cdn.min.js',
            $source,
            'SecurityHeaders should document that cdn.min.js is not a CSP-safe alternative (no ES module export).'
        );
    }

    public function test_csp_still_contains_nonce_directive(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'nonce-", $csp, 'CSP should still contain the nonce directive.');
    }

    public function test_csp_still_contains_strict_dynamic(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'strict-dynamic'", $csp, 'CSP should still contain strict-dynamic.');
    }

    private function runMiddlewareAndGetCsp(): string
    {
        $middleware = new \App\Http\Middleware\SecurityHeaders();
        $response = $middleware->handle(
            new \Illuminate\Http\Request(),
            fn () => new \Illuminate\Http\Response('ok')
        );

        return (string) $response->headers->get('Content-Security-Policy');
    }

    public function test_connect_src_allows_the_configured_sentry_ingest_host(): void
    {
        config(['sentry.dsn' => 'https://examplepublickey@o123456.ingest.us.sentry.io/7654321']);

        $csp = $this->runMiddlewareAndGetCsp();

        $this->assertStringContainsString(
            'connect-src',
            $csp,
            'CSP should keep the connect-src directive.'
        );
        $this->assertStringContainsString(
            'https://o123456.ingest.us.sentry.io',
            $csp,
            'Browser error reports must be able to reach the Sentry ingest host.'
        );
    }

    public function test_connect_src_is_unchanged_when_no_sentry_dsn_is_configured(): void
    {
        config(['sentry.dsn' => null]);

        $csp = $this->runMiddlewareAndGetCsp();

        $this->assertStringNotContainsString(
            'sentry.io',
            $csp,
            'Without a DSN the CSP must not open a Sentry connect destination.'
        );
    }

    public function test_non_https_sentry_dsn_is_not_added_to_connect_src(): void
    {
        config(['sentry.dsn' => 'http://localhost:9000/1']);

        $csp = $this->runMiddlewareAndGetCsp();

        $this->assertStringNotContainsString(
            'http://localhost:9000',
            $csp,
            'Only https ingest hosts belong in the production CSP.'
        );
    }
}
