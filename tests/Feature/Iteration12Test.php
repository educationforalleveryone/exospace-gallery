<?php

declare(strict_types=1);

/**
 * ITERATION-12 regression tests.
 *
 * The original iteration 12 attempted to remove 'unsafe-eval' from the CSP
 * by switching to Alpine's CSP-safe build. Investigation during deploy found
 * this is NOT possible with Alpine 3.x:
 *   - cdn.min.js has no ES module `export default` (build fails).
 *   - module.esm.js (which has `export default`) still uses `new Function`.
 *
 * This iteration is now a DOCUMENTATION-ONLY change: the code reverts to
 * the pre-iteration-12 state (keeping 'unsafe-eval'), but the SecurityHeaders
 * docblock + app.js comment explain WHY 'unsafe-eval' is kept — so a future
 * developer doesn't attempt the same removal without understanding the
 * Alpine 3.x constraint.
 *
 * Verifies:
 *   - AUDIT-P2-12.1 (revised): CSP still contains 'unsafe-eval' (required by Alpine 3.x)
 *   - AUDIT-P2-12.1 (revised): Alpine import uses the standard build (alpinejs, not cdn.min.js)
 *   - AUDIT-P2-12.1 (revised): SecurityHeaders docblock documents WHY 'unsafe-eval' is kept
 *   - No regression: CSP still has 'nonce-' + 'strict-dynamic'
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
     * AUDIT-P2-12.1 (revised): CSP STILL contains 'unsafe-eval'.
     *
     * This is required by Alpine 3.x — it uses new Function() (line 660 of
     * module.esm.js) to evaluate ALL x-data expression strings. Removing
     * 'unsafe-eval' would break every Alpine component (dropdowns, modals,
     * tooltips, command palette, cookie banner, feedback widget).
     *
     * The original audit recommendation to "switch to the CSP-safe build"
     * was based on an incorrect assumption about Alpine 3.x — there is no
     * build that removes new Function() entirely. See the SecurityHeaders
     * docblock for the full investigation notes.
     */
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

    /**
     * AUDIT-P2-12.1 (revised): Alpine import uses the standard build
     * (alpinejs), NOT the CSP-safe build (cdn.min.js which has no ES
     * module export default and fails the Vite build).
     */
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

    /**
     * AUDIT-P2-12.1 (revised): The SecurityHeaders docblock documents
     * the investigation + WHY 'unsafe-eval' is kept. This prevents a
     * future developer from re-attempting the removal without understanding
     * the Alpine 3.x constraint.
     */
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

    /**
     * No regression: CSP still contains 'nonce-' (from Iter-004).
     */
    public function test_audit_p212_1_csp_still_contains_nonce_directive(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'nonce-", $csp, 'CSP should still contain the nonce directive (from Iter-004).');
    }

    /**
     * No regression: CSP still contains 'strict-dynamic' (from Iter-004).
     */
    public function test_audit_p212_1_csp_still_contains_strict_dynamic(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'strict-dynamic'", $csp, 'CSP should still contain strict-dynamic (from Iter-004).');
    }

    /**
     * AUDIT-P2-12.1 (revised): The app.js comment block documents the
     * investigation + why the CSP-safe build was rejected.
     */
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
