<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityHeaders — applies baseline security headers to every response.
 *
 * ITERATION-004 FIX (audit D-8): CSP now uses nonces instead of 'unsafe-inline'.
 *
 * Previously: `script-src 'self' 'unsafe-inline' 'unsafe-eval'`. The
 * 'unsafe-inline' let any inline <script> execute (dominant XSS vector).
 * 'unsafe-eval' let eval() and new Function() execute (needed by Alpine.js's
 * default build which compiles x-data expressions via Function()).
 *
 * FIX:
 *   - 'unsafe-inline' replaced with per-request nonces. Inline scripts
 *     must include nonce="{nonce}" to execute. The nonce is generated
 *     per-request and exposed via the `csp_nonce()` helper / Blade directive.
 *   - 'unsafe-eval' removed. The Alpine.js CSP-safe build
 *     (alpinejs/dist/cdn.min.js) uses MutationObserver instead of eval(),
 *     so 'unsafe-eval' is no longer needed. (The Alpine package must be
 *     updated to the CSP-safe build — documented in Manual_Actions.md.)
 *   - 'strict-dynamic' added so that scripts loaded by a trusted (nonce'd)
 *     script can also execute without their own nonce. This is the modern
 *     CSP pattern for apps that use bundlers (Vite) that inject child
 *     scripts dynamically.
 *
 * The nonce is stored in the request attributes so the Blade @nonce
 * directive and the csp_nonce() helper can access it during view rendering.
 *
 * SEC-2 FIX (preserved): Removed the three third-party CDN allowlists.
 * Tightened img-src from 'https:' to 'self' data: blob:.
 * Added HSTS preload directive.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // D-8 FIX: Generate a per-request CSP nonce.
        // 32 bytes of randomness (256 bits) — sufficient for CSP nonces.
        $nonce = base64_encode(Str::random(32));
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);

        // ── Standard hardening headers ───────────────────────────────────────
        // SEC-2: Added preload directive for HSTS preload list eligibility
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // ── Permissions-Policy ──────────────────────────────────────────────
        $response->headers->set('Permissions-Policy', implode(', ', [
            'camera=()',
            'microphone=()',
            'geolocation=()',
            'payment=(self "https://www.2checkout.com")',
            'gyroscope=(self)',
            'accelerometer=(self)',
        ]));

        // ── Content-Security-Policy ──────────────────────────────────────────
        if (app()->environment('local')) {
            // D-8 FIX: In local dev, still generate the nonce (so the @nonce
            // directive works) but don't enforce CSP — local dev tools
            // (Vite HMR, Laravel Telescope, Debugbar) inject inline scripts
            // that would be blocked by strict CSP.
            return $response;
        }

        // D-8 FIX: CSP with nonces + strict-dynamic.
        //
        // - 'nonce-{nonce}': inline scripts with nonce="{nonce}" attribute execute.
        // - 'strict-dynamic': scripts loaded by a trusted (nonce'd) script also
        //   execute. This handles Vite's dynamic imports without listing every
        //   chunk in the CSP.
        // - 'unsafe-eval' REMOVED: requires the Alpine.js CSP-safe build.
        // - 'unsafe-inline' REMOVED: replaced by nonces.
        //
        // style-src still has 'unsafe-inline' because Laravel's Blade
        // generates inline styles (e.g. <style> blocks in layouts) and
        // style nonces are more complex to implement. This is a known
        // tradeoff — style-based XSS is much rarer than script-based XSS.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "img-src 'self' data: blob:",
            "font-src 'self' data: https://fonts.bunny.net",
            "media-src 'self' blob:",
            "connect-src 'self' https://fonts.bunny.net",
            "worker-src 'self' blob:",
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
