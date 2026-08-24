<?php

declare(strict_types=1);

/**
 * ITERATION-12 regression tests.
 *
 * Verifies:
 *   - AUDIT-P2-12.1: CSP no longer contains 'unsafe-eval' (the XSS attack surface)
 *   - AUDIT-P2-12.1: Alpine.js import uses the CSP-safe build (cdn.min.js)
 *   - AUDIT-P2-12.1: CSP still has 'nonce-' + 'strict-dynamic' (no regression)
 *
 * Run: php artisan test --filter=Iteration12Test
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Iteration12Test extends TestCase
{
    use RefreshDatabase;

    /**
     * AUDIT-P2-12.1: The CSP header does NOT contain 'unsafe-eval'.
     *
     * 'unsafe-eval' was the one CSP relaxation that defeated most of the
     * point of having a CSP — it allows eval() and new Function(), which
     * are the primary XSS vectors. Removing it (by switching to Alpine's
     * CSP-safe build) closes this attack surface.
     */
    public function test_audit_p212_1_csp_does_not_contain_unsafe_eval(): void
    {
        // Force non-local environment so CSP is enforced.
        $this->app['env'] = 'production';

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp, 'CSP header should be set in non-local environments.');
        $this->assertStringNotContainsString(
            "'unsafe-eval'",
            $csp,
            'AUDIT-P2-12.1: CSP must NOT contain unsafe-eval — it allows eval() and new Function(), the primary XSS vectors.'
        );
    }

    /**
     * AUDIT-P2-12.1: The CSP still contains 'nonce-' (the nonce-based
     * inline script allowlist from Iter-004 — no regression).
     */
    public function test_audit_p212_1_csp_still_contains_nonce_directive(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'nonce-", $csp, 'CSP should still contain the nonce directive (from Iter-004).');
    }

    /**
     * AUDIT-P2-12.1: The CSP still contains 'strict-dynamic' (from Iter-004
     * — no regression). This lets Vite-loaded scripts execute without
     * individual nonces.
     */
    public function test_audit_p212_1_csp_still_contains_strict_dynamic(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'strict-dynamic'", $csp, 'CSP should still contain strict-dynamic (from Iter-004).');
    }

    /**
     * AUDIT-P2-12.1: The Alpine.js import in resources/js/app.js uses
     * the CSP-safe build (alpinejs/dist/cdn.min.js), not the default
     * build (alpinejs).
     */
    public function test_audit_p212_1_alpine_import_uses_csp_safe_build(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString(
            "alpinejs/dist/cdn.min.js",
            $appJs,
            'AUDIT-P2-12.1: app.js should import the CSP-safe Alpine build (alpinejs/dist/cdn.min.js), not the default build.'
        );

        // Verify the default import is NOT present (would be just 'alpinejs' without the /dist/ path).
        // We check that 'from \'alpinejs\'' (exact, without /dist/) is NOT present.
        $this->assertStringNotContainsString(
            "from 'alpinejs';",
            $appJs,
            'AUDIT-P2-12.1: app.js should NOT import the default Alpine build (which requires unsafe-eval).'
        );
    }

    /**
     * AUDIT-P2-12.1: The SecurityHeaders middleware docblock documents
     * the 'unsafe-eval' removal + the Alpine CSP-safe build switch.
     * (Source-grep test — catches a future refactor that silently
     * re-adds unsafe-eval without documentation.)
     */
    public function test_audit_p212_1_security_headers_documents_unsafe_eval_removal(): void
    {
        $source = file_get_contents(app_path('Http/Middleware/SecurityHeaders.php'));

        $this->assertStringContainsString(
            'AUDIT-P2-12.1',
            $source,
            'SecurityHeaders should document the AUDIT-P2-12.1 change.'
        );
        $this->assertStringContainsString(
            "'unsafe-eval' REMOVED",
            $source,
            'SecurityHeaders should document that unsafe-eval was removed.'
        );
    }
}
