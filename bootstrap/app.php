<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\DetectCustomDomain::class);

        $middleware->prepend(\App\Http\Middleware\SeoRedirects::class);

        $middleware->prepend(\App\Http\Middleware\RequestId::class);

        $middleware->validateCsrfTokens(except: [
            'webhooks/2checkout',
            'webhooks/2checkout/refund',
            'unsubscribe/one-click/*',
        ]);

        $middleware->prepend(\App\Http\Middleware\ScopeSessionDomain::class);

        // 1. Security Headers
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->web(append: [
            \App\Http\Middleware\CaptureAcquisitionContext::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\CheckBanned::class,
            \App\Http\Middleware\CheckPlanExpiry::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\PreventAuthenticatedCaching::class,
        ]);

        $trustedProxies = env('TRUSTED_PROXIES');
        if ($trustedProxies && $trustedProxies !== '*') {
            $middleware->trustProxies(at: $trustedProxies);
        } elseif ($trustedProxies === '*') {
            // Permissive mode — warn later (AppServiceProvider / PreflightCheck).
            $middleware->trustProxies(at: '*');
        }
        // If null/empty: trust no proxies (fail-closed)

        $middleware->alias([
            'super_admin'   => \App\Http\Middleware\EnsureUserIsSuperAdmin::class,
            'mfa'           => \App\Http\Middleware\RequireMfa::class,
            'feature_flag'  => \App\Http\Middleware\EnsureFeatureFlagEnabled::class,
            'ability'       => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'abilities'     => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'ops_access'    => \App\Ops\Http\Middleware\EnsureOpsAccess::class,
            'ops_operator'  => \App\Ops\Http\Middleware\EnsureOpsOperator::class,

            'cc_access'     => \App\Http\Middleware\EnsureControlCenterAccess::class,
        ]);

        // API clients that omit the Accept header must still receive machine-readable
        // errors (401/422 JSON), never framework redirects or HTML pages.
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Unmatched /api/* paths never reach route middleware, so JSON rendering
        // for them is decided here instead.
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $e) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->report(function (\Throwable $e): void {
            try {
                app(\App\Ops\Services\OpsExceptionReporter::class)->record($e);
            } catch (\Throwable) {
                // Deliberately swallowed — see OpsExceptionReporter.
            }
        });
    })->create();
