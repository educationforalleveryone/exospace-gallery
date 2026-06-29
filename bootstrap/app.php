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

        // 4. Trusted proxies
        $middleware->trustProxies(at: '*');

        // 5. Middleware aliases
        $middleware->alias([
            'super_admin' => \App\Http\Middleware\EnsureUserIsSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
