<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityHeaders — applies baseline security headers to every response.
 *
 * SEC-2 FIX: Removed the three third-party CDN allowlists (cdn.tailwindcss.com,
 * cdn.jsdelivr.net, unpkg.com) from script-src. These were needed when admin
 * and marketing pages loaded SortableJS/Dropzone from CDNs, but all pages
 * now use Vite-bundled assets. Also removed unpkg.com from style-src.
 * Tightened img-src from 'https:' (allows ANY HTTPS image) to 'self' data: blob:.
 * Added HSTS preload directive.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // ── Standard hardening headers ───────────────────────────────────────
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
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
            return $response;
        }

        $csp = implode('; ', [
            "default-src 'self'",
            // SEC-2: Removed cdn.tailwindcss.com, cdn.jsdelivr.net, unpkg.com.
            // All scripts are now Vite-bundled. 'unsafe-eval' is still needed
            // for Alpine.js (compiles x-data expressions at runtime).
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            // SEC-2: Removed unpkg.com — Dropzone CSS is Vite-bundled.
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            // SEC-2: Tightened from 'https:' (allows ANY HTTPS image) to 'self'.
            // Prevents malicious admins from embedding tracking pixels.
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
