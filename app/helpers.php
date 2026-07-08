<?php

declare(strict_types=1);

// D-8 FIX (moved out of AppServiceProvider::boot() — see git history).
//
// This MUST NOT live inside a service provider's boot() method. Laravel's
// `route:cache` (and `event:cache`) commands build a second, fresh
// Application instance in-process via RouteCacheCommand::getFreshApplication()
// and boot it again — so anything declared inside boot() runs twice in the
// same PHP process. A conditionally-declared global function inside boot()
// then throws "Cannot redeclare csp_nonce()" during `php artisan route:cache`.
//
// Loading this file once via composer.json's "autoload.files" (which runs
// during Composer's autoloader bootstrap, before Laravel/service providers
// exist at all) guarantees it executes exactly once per process, regardless
// of how many times providers boot.
if (! function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        $request = app(\Illuminate\Http\Request::class);

        return (string) $request->attributes->get('csp_nonce', '');
    }
}