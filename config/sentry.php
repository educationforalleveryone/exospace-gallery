<?php

/**
 * P3-1: Sentry error tracking configuration.
 *
 * To enable Sentry:
 *   1. Run: composer require sentry/sentry-laravel
 *   2. Set SENTRY_LARAVEL_DSN in your .env
 *   3. Set SENTRY_ENVIRONMENT=production in your .env
 *
 * If the package is not installed or the DSN is empty, Sentry is a no-op.
 * The config file is structured so Laravel doesn't crash if the package
 * isn't installed — the Sentry SDK checks for the DSN at runtime.
 */
return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // Capture release as git sha
    'release' => env('SENTRY_RELEASE'),

    // Set the environment
    'environment' => env('SENTRY_ENVIRONMENT', app()->environment()),

    // When left empty or `null` the Laravel logging level will be used
    'log_level' => env('SENTRY_LOG_LEVEL', 'error'),

    // Capture login stack traces
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    // Capture silenced errors
    'silence_errors' => false,

    // Breadcrumbs
    'breadcrumbs' => [
        // Capture SQL queries as breadcrumbs
        'sql_queries' => true,
        // Capture bindings on SQL queries logged into breadcrumbs
        'sql_bindings' => false,
    ],
];
