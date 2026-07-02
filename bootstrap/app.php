<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 0. Custom domain resolution — runs FIRST so that the host header
        //    is checked before route matching. If a gallery is resolved,
        //    GalleryViewController will render it regardless of the URL path.
        $middleware->prepend(\App\Http\Middleware\DetectCustomDomain::class);

        // 0b. Exempt 2Checkout IPN webhook routes from CSRF protection.
        //     2Checkout's INS (Instant Notification Service) sends server-to-
        //     server POSTs that do not carry a Laravel CSRF token. Without
        //     this exemption every webhook returns HTTP 419 and no paying
        //     customer is ever upgraded.
        //
        //     SECURITY: these routes are instead authenticated by the HMAC
        //     signature verification in WebhookController::verify2CheckoutHash().
        //     A request that fails signature verification is rejected with 403
        //     before any state mutation occurs. See WebhookController for the
        //     signature algorithm. Do NOT add other routes here lightly.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        // 0a. Scope session cookie domain to the request host — runs BEFORE
        //     the session middleware so config('session.domain') is set
        //     correctly when the cookie is baked. Fixes the PIN-session
        //     bug on custom-domain requests (Round 3).
        $middleware->prepend(\App\Http\Middleware\ScopeSessionDomain::class);

        // 1. Security Headers
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // 2. Banned users check — runs on every authenticated request
        $middleware->append(\App\Http\Middleware\CheckBanned::class);

        // 3. Plan expiry check — auto-downgrades expired paid plans
        $middleware->append(\App\Http\Middleware\CheckPlanExpiry::class);

        // 4. Trusted proxies — restrict to the actual reverse-proxy network
        //    instead of trusting every caller. (Task C17)
        //
        //    WHY: `trustProxies(at: '*')` trusts any client's
        //    X-Forwarded-Host / X-Forwarded-Proto / X-Forwarded-For headers.
        //    An attacker who can reach the app directly (bypassing Coolify's
        //    Traefik) can spoof these headers — enabling host-header session
        //    attacks (ScopeSessionDomain reads $request->getHost()), custom-
        //    domain spoofing (DetectCustomDomain reads $request->getHost()),
        //    and IP-spoofing (which weakens rate limiting and audit logging).
        //
        //    The TRUSTED_PROXIES env var should be set in production to
        //    Coolify's internal docker network (typically 172.16.0.0/12 or
        //    the specific Traefik container IP). Find it via:
        //       docker network inspect coolify-network | grep Subnet
        //
        //    Acceptable values:
        //      - specific IPs: "10.0.0.5,10.0.0.6"
        //      - CIDR ranges: "172.16.0.0/12"
        //      - "*" (legacy permissive — logs a warning in PreflightCheck)
        //
        //    Leaving empty/unset is FAIL-OPEN to '*' for backward
        //    compatibility — existing deployments that haven't set the
        //    env var keep working. New deployments should set it.
        $trustedProxies = env('TRUSTED_PROXIES', '*');
        $middleware->trustProxies(at: $trustedProxies);

        // 5. Middleware aliases
        $middleware->alias([
            'super_admin' => \App\Http\Middleware\EnsureUserIsSuperAdmin::class,
            'mfa'         => \App\Http\Middleware\RequireMfa::class, // (Task H56)
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
