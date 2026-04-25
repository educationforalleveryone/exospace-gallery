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
        // 1. Security Headers
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // 2. Banned users check — runs on every authenticated request
        $middleware->append(\App\Http\Middleware\CheckBanned::class);

        // 3. Trusted proxies
        $middleware->trustProxies(at: '*');

        // 4. Middleware aliases
        $middleware->alias([
            'super_admin' => \App\Http\Middleware\EnsureUserIsSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();