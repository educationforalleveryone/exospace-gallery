<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityHeaders — applies baseline security headers to every response.
 *
 * Content-Security-Policy allows:
 *   - 'self' for everything by default
 *   - Inline scripts + styles (Laravel csrf meta, Blade <style> blocks,
 *     GALLERY_DATA injection)
 *   - 'unsafe-eval' for scripts (Alpine.js compiles x-data expressions
 *     at runtime via new Function())
 *   - The CDNs still used by legacy admin/marketing pages:
 *       cdn.tailwindcss.com  (Tailwind CDN on some admin pages)
 *       cdn.jsdelivr.net     (Alpine CDN, SortableJS)
 *       unpkg.com            (Dropzone CSS, SortableJS)
 *       fonts.bunny.net      (Inter font — CSS + woff2 files)
 *     These will be removed in v3.1 once admin/marketing pages are
 *     migrated to Vite-bundled assets like the gallery viewer was.
 *   - data: URIs for images and fonts (necessary for some Three.js
 *     textures and inline SVG icons)
 *   - blob: URIs for media and workers (Three.js GLTFLoader + KTX2Decoder)
 *
 * No third-party CDNs are allowed for the gallery viewer itself —
 * Three.js, GSAP, GLTFLoader, DRACOLoader, KTX2Loader are all bundled
 * by Vite.
 *
 * If you add a future integration that needs to load from a third-party
 * origin (e.g. Stripe.js), add it to the relevant CSP directive below.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // ── Standard hardening headers ───────────────────────────────────────
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // ── Permissions-Policy (formerly Feature-Policy) ─────────────────────
        // Lock down browser features the gallery doesn't need
        $response->headers->set('Permissions-Policy', implode(', ', [
            'camera=()',
            'microphone=()',
            'geolocation=()',
            'payment=(self "https://www.2checkout.com")',
            'gyroscope=(self)',       // used by potential future mobile gyro-look
            'accelerometer=(self)',
        ]));

        // ── Content-Security-Policy ──────────────────────────────────────────
        // Skip CSP in local dev — Vite HMR + Alpine eval break it. The dev
        // server already runs on localhost with no real attack surface.
        if (app()->environment('local')) {
            return $response;
        }

        $csp = implode('; ', [
            "default-src 'self'",
            // Scripts: self + inline (Laravel csrf meta + GALLERY_DATA injection)
            // + 'unsafe-eval' (Alpine.js compiles expressions at runtime)
            // + the CDNs still used by marketing/admin pages (Tailwind CDN,
            //   Alpine CDN, SortableJS). Remove these once those pages are
            //   migrated to Vite-bundled assets like the gallery viewer was.
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://unpkg.com",
            // Styles: self + inline (Blade <style> blocks) + Dropzone CSS from
            // unpkg + Inter font CSS from fonts.bunny.net (loaded in app + guest
            // layouts since v2.3.0 typography upgrade).
            "style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.bunny.net",
            // Images: self + data: (SVG icons + base64) + blob: (Three.js textures)
            "img-src 'self' data: blob: https:",
            // Fonts: self + data: + fonts.bunny.net (Inter woff2 files served
            // from the same origin as the CSS).
            "font-src 'self' data: https://fonts.bunny.net",
            // Media: self + blob: (Three.js AudioLoader)
            "media-src 'self' blob:",
            // Connects: self (for fetch / analytics) + fonts.bunny.net
            // (the <link rel="preconnect"> in layouts opens a TCP connection
            // to bunny.net before the CSS request — needs to be allowed here).
            "connect-src 'self' https://fonts.bunny.net",
            // Workers: blob: (Three.js KTX2Decoder runs in a blob worker)
            "worker-src 'self' blob:",
            // Frames: same-origin only (embed mode uses iframes)
            "frame-src 'self'",
            // Object-src: none — no plugins allowed
            "object-src 'none'",
            // Base-uri: self — prevents <base> tag injection
            "base-uri 'self'",
            // Form-action: self — prevents form posts to attacker origins
            "form-action 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
