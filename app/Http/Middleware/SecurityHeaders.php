<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    // Routes that are legitimate iframe targets. gallery.view is the public
    // exhibition page that owners embed via the generated snippet; the two
    // preview routes are only ever framed by the app itself (venue pages and
    // the admin live-preview panel).
    private const EMBEDDABLE_ANYWHERE = ['gallery.view'];

    private const EMBEDDABLE_SAME_ORIGIN = ['venues.preview', 'admin.galleries.preview'];

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(Str::random(32));
        $request->attributes->set('csp_nonce', $nonce);

        // One shared nonce for every script channel: @vite emits it on all
        // entry tags (required — under 'strict-dynamic' browsers ignore
        // 'self'), Livewire reads the same value for its injected tags, and
        // Blade inline scripts use the @nonce directive.
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Capabilities the app itself does not use are closed; fullscreen and
        // audio autoplay stay granted to self — the 3D viewer uses both, and
        // the embed snippet delegates them via its allow attribute.
        $response->headers->set('Permissions-Policy', implode(', ', [
            'camera=()',
            'microphone=()',
            'geolocation=()',
            'payment=()',
            'gyroscope=()',
            'accelerometer=()',
            'fullscreen=(self)',
            'autoplay=(self)',
        ]));

        // HSTS only on a genuinely secure response: behind the reverse proxy
        // isSecure() is driven by X-Forwarded-Proto from the trusted proxy
        // range. No preload (one-way commitment) and no includeSubDomains
        // (subdomain safety is not part of this deployment's contract).
        if (! app()->environment('local') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        [$frameAncestors, $frameOptions] = $this->framePolicy($request);

        if ($frameOptions !== null) {
            $response->headers->set('X-Frame-Options', $frameOptions);
        }

        if (! app()->environment('local')) {
            // KEPT 'unsafe-eval' in script-src: Alpine 3.x compiles x-data
            // expressions with new Function(), which a strict CSP would block.
            // The cdn.min.js build is not a CSP-safe alternative (no ES module
            // export — the Vite build fails).
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
                "img-src 'self' data: blob:",
                "font-src 'self' data: https://fonts.bunny.net",
                "media-src 'self' blob:",
                "connect-src 'self' blob:".$this->sentryConnectSource(),
                "worker-src 'self' blob:",
                "frame-src 'self' https://challenges.cloudflare.com",
                "frame-ancestors {$frameAncestors}",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]);

            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }

    /**
     * Embedding is a product feature with per-route boundaries: exhibitions
     * may be embedded anywhere, the two preview routes accept same-origin
     * framing only, and every other response refuses framing outright.
     *
     * @return array{0: string, 1: string|null}
     */
    private function framePolicy(Request $request): array
    {
        $route = $request->route()?->getName();

        if (in_array($route, self::EMBEDDABLE_ANYWHERE, true)) {
            return ['*', null];
        }

        if (in_array($route, self::EMBEDDABLE_SAME_ORIGIN, true)) {
            return ["'self'", 'SAMEORIGIN'];
        }

        return ["'none'", 'DENY'];
    }

    // Browser error reports go to the Sentry ingest host, so it must be an
    // allowed connect destination whenever a DSN is configured.
    private function sentryConnectSource(): string
    {
        $dsn = (string) config('sentry.dsn');
        $host = parse_url($dsn, PHP_URL_HOST);
        $scheme = parse_url($dsn, PHP_URL_SCHEME);

        if (! $host || $scheme !== 'https') {
            return '';
        }

        return ' https://'.$host;
    }
}
