<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Iteration12Test extends TestCase
{
    use RefreshDatabase;

    public function test_audit_p212_1_csp_still_contains_unsafe_eval_required_by_alpine(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp, 'CSP header should be set in non-local environments.');
        $this->assertStringContainsString(
            "'unsafe-eval'",
            $csp,
            'AUDIT-P2-12.1 (revised): CSP must keep unsafe-eval — Alpine 3.x requires new Function() for x-data expressions.'
        );
    }

    public function test_audit_p212_1_alpine_import_uses_standard_build(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));

        // The standard import should be present.
        $this->assertStringContainsString(
            "import Alpine from 'alpinejs';",
            $appJs,
            'AUDIT-P2-12.1 (revised): app.js should use the standard Alpine import (alpinejs).'
        );

        // The broken CSP-safe import should NOT be present.
        $this->assertStringNotContainsString(
            "import Alpine from 'alpinejs/dist/cdn.min.js';",
            $appJs,
            'AUDIT-P2-12.1 (revised): app.js should NOT import cdn.min.js (no ES module export default — build fails).'
        );
    }

    public function test_audit_p212_1_security_headers_documents_why_unsafe_eval_is_kept(): void
    {
        $source = file_get_contents(app_path('Http/Middleware/SecurityHeaders.php'));

        $this->assertStringContainsString(
            'AUDIT-P2-12.1',
            $source,
            'SecurityHeaders should reference AUDIT-P2-12.1.'
        );
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
            'SecurityHeaders should document that cdn.min.js was investigated + rejected (no ES module export).'
        );
    }

    public function test_audit_p212_1_csp_still_contains_nonce_directive(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'nonce-", $csp, 'CSP should still contain the nonce directive (from Iter-004).');
    }

    public function test_audit_p212_1_csp_still_contains_strict_dynamic(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'strict-dynamic'", $csp, 'CSP should still contain strict-dynamic (from Iter-004).');
    }

    public function test_audit_p212_1_app_js_documents_investigation(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString(
            'REVERTED',
            $appJs,
            'app.js should document that the CSP-safe build attempt was REVERTED.'
        );
        $this->assertStringContainsString(
            'new Function',
            $appJs,
            'app.js should explain that Alpine 3.x uses new Function for expression evaluation.'
        );
    }
}
