<?php

declare(strict_types=1);

return [

    'platform_sync' => [
        'enabled' => filter_var(env('OPS_PLATFORM_SYNC_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'timeout' => 15,
        // How many recent deployments to inspect per application per sync.
        'deployments_limit' => 5,
    ],

    'self' => [
        'name' => env('APP_NAME', 'Exospace'),
        'url' => env('APP_URL'),
        'environment' => env('APP_ENV', 'production'),
        'coolify_uuid' => env('COOLIFY_APPLICATION_UUID'),
    ],

    'ingest' => [
        'tokens' => env('OPS_INGEST_TOKENS'),
        'max_message_length' => 8000,
        'max_title_length' => 250,
        'max_context_bytes' => 16384,
        'requests_per_minute' => 30,
    ],

    'retention' => [
        'auto_resolve_days' => (int) env('OPS_EVENTS_AUTO_RESOLVE_DAYS', 7),
        'resolved_retention_days' => (int) env('OPS_EVENTS_RESOLVED_RETENTION_DAYS', 90),
    ],

    'log_tap' => [
        'level' => env('OPS_LOG_TAP_LEVEL', 'warning'),
    ],

    'dashboard' => [
        // How many events/deployments to show per page.
        'per_page' => 25,
        'recent_window_hours' => 24,
    ],

    'diagnostics' => [
        // Diagnostic runs older than N days are deleted by ops:prune-events.
        'retention_days' => (int) env('OPS_DIAGNOSTIC_RETENTION_DAYS', 30),
    ],

    'actions' => [
        'enabled' => filter_var(env('OPS_ACTIONS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'sentry' => [
        'api_token' => env('SENTRY_API_TOKEN'),
        // sentry.io for the hosted service; override for self-hosted.
        'base_url' => rtrim((string) env('SENTRY_API_BASE_URL', 'https://sentry.io'), '/'),
        'org' => env('SENTRY_ORG_SLUG'),
        'projects' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SENTRY_PROJECT_SLUGS', '')),
        ))),
        'timeout' => 10,
        'cache_minutes' => (int) env('SENTRY_SUMMARY_CACHE_MINUTES', 10),
        // How many top issues the tile lists.
        'limit' => 5,
    ],

    'sweeps' => [
        'enabled' => filter_var(env('OPS_SWEEP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'diagnostics' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'OPS_SWEEP_DIAGNOSTICS',
                'database.connectivity,redis.connectivity,queue.health,server.disk,app.scheduler',
            )),
        ))),
        'cadences' => collect(array_map('trim', explode(',', (string) env('OPS_SWEEP_CADENCES', ''))))
            ->filter(fn ($entry) => $entry !== '' && str_contains($entry, ':'))
            ->mapWithKeys(function ($entry) {
                $parts = explode(':', $entry, 2);

                return [trim((string) $parts[0]) => (int) ($parts[1] ?? 0)];
            })
            ->filter(fn ($minutes) => $minutes > 0)
            ->all(),
    ],

    'access' => [
        'viewer_enabled' => filter_var(env('OPS_VIEWER_ACCESS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'operator_enabled' => filter_var(env('OPS_OPERATOR_ACCESS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'credentials' => [

        'reminders_enabled' => filter_var(env('OPS_CREDENTIAL_REMINDERS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'digest' => [
        'enabled' => filter_var(env('OPS_MORNING_DIGEST_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        'watchdog_enabled' => filter_var(env('OPS_DIGEST_WATCHDOG_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'weekly_review' => [
        'enabled' => filter_var(env('OPS_WEEKLY_REVIEW_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        'snapshot_retention_days' => (int) env('OPS_WEEKLY_REVIEW_SNAPSHOT_RETENTION_DAYS', 365),
    ],
];
