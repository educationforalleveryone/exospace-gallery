<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OpsCenter — Operations Control Plane
    |--------------------------------------------------------------------------
    |
    | Iteration 1 of the OpsCenter project (see docs/OPS_DISCOVERY_AUDIT.md).
    | This config centralizes every env read for the App\Ops module so that
    | `php artisan config:cache` is safe (same convention as
    | config/services.php — env() is ONLY read here, never at runtime).
    |
    | The module aggregates EXISTING systems (Sentry, OperationalAlertService,
    | JobHeartbeatService, spatie backups, webhook ledgers, the Coolify API)
    | into a unified operations view. It does not replace them.
    |
    */

    // ── Platform sync (Coolify API) ─────────────────────────────────────
    //
    // Pulls server/application/database/service/deployment state from the
    // Coolify REST API every sync interval. Shares the token configured in
    // config/services.php → 'coolify' (COOLIFY_API_TOKEN etc.) — the same
    // credentials CoolifyDomainManager already uses. No new secrets.
    //
    // Set OPS_PLATFORM_SYNC_ENABLED=false to disable the scheduled sync
    // (the dashboard then shows only locally-ingested events).
    'platform_sync' => [
        'enabled' => filter_var(env('OPS_PLATFORM_SYNC_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Per-request HTTP timeout for Coolify API calls. Coolify is on the
        // same box; 15s is generous while still bounded.
        'timeout' => 15,
        // How many recent deployments to inspect per application per sync.
        'deployments_limit' => 5,
    ],

    // ── This application (self-registration) ────────────────────────────
    //
    // The host app (Exospace) registers itself in ops_applications on the
    // first sync/boot so its own errors are attributed to a real row.
    'self' => [
        'name' => env('APP_NAME', 'Exospace'),
        'url' => env('APP_URL'),
        'environment' => env('APP_ENV', 'production'),
        // The Coolify UUID of THIS application (already present in env for
        // CoolifyDomainManager). Used to mark is_self and to correlate
        // Coolify deployments with local errors.
        'coolify_uuid' => env('COOLIFY_APPLICATION_UUID'),
    ],

    // ── Event ingestion API (for OTHER applications) ────────────────────
    //
    // POST /api/ops/ingest lets the other applications on the Coolify
    // server report errors/events WITHOUT any agent process, Docker socket
    // access, or inbound ports — a single authenticated HTTPS call.
    //
    // Token format in OPS_INGEST_TOKENS (comma-separated):
    //     OPS_INGEST_TOKENS=project-b=abc123...,project-c=def456...
    // The key ("project-b") becomes the application slug in ops_applications;
    // the value is the shared secret the app sends via the X-Ops-Token
    // header. Tokens are compared with hash_equals() against a sha256 of
    // the provided value — timing-safe.
    //
    // Leave empty to disable the endpoint entirely (fail-closed 404 — the
    // same convention as MetricsController).
    //
    // Generate a token with: openssl rand -hex 24
    'ingest' => [
        'tokens' => env('OPS_INGEST_TOKENS'),
        // Hard caps on untrusted payload sizes. Large payloads are rejected
        // with 422 rather than truncated mid-structure (the caller should fix
        // their reporter; ops events are headlines, not log dumps).
        'max_message_length' => 8000,
        'max_title_length' => 250,
        'max_context_bytes' => 16384,
        'requests_per_minute' => 30,
    ],

    // ── Retention (documented, bounded growth) ──────────────────────────
    //
    // ops_events stores DEDUPLICATED aggregates (one row per distinct error
    // per application), NOT raw log lines — raw logs keep their existing
    // home (daily files, LOG_DAILY_DAYS=30) and Sentry keeps traces. This
    // keeps the ops table small enough that 90-day retention is trivial.
    //
    //   auto_resolve_days: an event with no recurrence for N days is marked
    //                       resolved (status='resolved', resolved_at=now).
    //   resolved_retention_days: resolved events older than N days are
    //                       deleted by ops:prune-events.
    //   open retention:    unbounded — an ongoing problem is never silently
    //                       deleted. Resolve it (or let auto-resolve do it)
    //                       first.
    'retention' => [
        'auto_resolve_days' => (int) env('OPS_EVENTS_AUTO_RESOLVE_DAYS', 7),
        'resolved_retention_days' => (int) env('OPS_EVENTS_RESOLVED_RETENTION_DAYS', 90),
    ],

    // ── Log tap ─────────────────────────────────────────────────────────
    //
    // The 'ops' logging channel (config/logging.php) mirrors warning+ log
    // records into ops_events. It is added to LOG_STACK in production
    // (e.g. LOG_STACK=daily,ops — see MASTER_MANUAL_OPERATIONS.md). The tap
    // is strictly additive: existing channels keep working unchanged.
    'log_tap' => [
        // Minimum monolog level that reaches the tap. 'warning' filters out
        // the routine info noise; 'notice' widens when debugging.
        'level' => env('OPS_LOG_TAP_LEVEL', 'warning'),
    ],

    // ── Dashboard ────────────────────────────────────────────────────────
    'dashboard' => [
        // How many events/deployments to show per page.
        'per_page' => 25,
        // Overview "recent events" window.
        'recent_window_hours' => 24,
    ],

    // ── Diagnostic engine (Iteration 3) ─────────────────────────────────
    //
    // The engine runs ONLY the allow-listed read-only checks declared in
    // App\Ops\Diagnostics\DiagnosticRegistry (database, Redis, queue,
    // container, deployment, server, application). Every run is redacted,
    // audited (ops.diagnostic.run) and persisted to ops_diagnostic_runs.
    //
    // Runs are point-in-time snapshots, always reproducible on demand —
    // short retention loses nothing.
    'diagnostics' => [
        // Diagnostic runs older than N days are deleted by ops:prune-events.
        'retention_days' => (int) env('OPS_DIAGNOSTIC_RETENTION_DAYS', 30),
    ],

    // ── Actions (Iteration 3) ───────────────────────────────────────────
    //
    // The allow-listed write surface (App\Ops\Actions\OpsActionRegistry):
    // platform.sync (risk: none), app.restart + webhook.replay (risk:
    // elevated — password + typed confirmation phrase + audit + Slack).
    //
    // Kill switch: set OPS_ACTIONS_ENABLED=false to fail-close the entire
    // action surface (routes 404, UI hides actions). Diagnostics are
    // unaffected — they are read-only.
    'actions' => [
        'enabled' => filter_var(env('OPS_ACTIONS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
