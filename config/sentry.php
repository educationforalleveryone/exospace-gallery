<?php

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // Capture release as git sha
    'release' => env('SENTRY_RELEASE'),

    // Set the environment
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    // Capture login stack traces
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    // Breadcrumbs
    'breadcrumbs' => [
        // Capture SQL queries as breadcrumbs
        'sql_queries' => true,
        // Capture bindings on SQL queries logged into breadcrumbs
        'sql_bindings' => false,
    ],
];
