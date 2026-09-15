<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(Str::random(32));
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);

        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        $response->headers->set('Permissions-Policy', implode(', ', [
            'camera=()',
            'microphone=()',
            'geolocation=()',
            'payment=(self "https://www.2checkout.com")',
            'gyroscope=(self)',
            'accelerometer=(self)',
        ]));

        if (app()->environment('local')) {
            return $response;
        }

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
            "connect-src 'self' https://fonts.bunny.net blob:" . $this->sentryConnectSource(),
            "worker-src 'self' blob:",
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
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

        return ' https://' . $host;
    }
}
