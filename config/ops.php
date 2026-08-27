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

    // ── Sentry API summary (Iteration 4) ───────────────────────────────
    //
    // Read-only bridge to the Sentry REST API for the OpsCenter overview
    // tile ("Sentry — Unresolved Issues"). Sentry stays the deep-dive UI
    // (ADR: summarize + link out, never clone) — this only pulls issue
    // HEADLINES so the operator sees, in one place, "Sentry knows about N
    // unresolved issues, here are the top ones" next to the local view.
    //
    // Completely OPTIONAL and independent of SENTRY_LARAVEL_DSN:
    //   - DSN          = error REPORTING (exceptions → Sentry) — unchanged.
    //   - API token    = error READING (Sentry → OpsCenter summary tile).
    //
    // Create a token at sentry.io → Settings → Auth Tokens with scopes
    // org:read + project:read (+ event:read where the org requires it).
    // Leaving the token/org empty simply hides the tile's data with an
    // honest "not configured" note — nothing else changes.
    'sentry' => [
        'api_token' => env('SENTRY_API_TOKEN'),
        // sentry.io for the hosted service; override for self-hosted.
        'base_url' => rtrim((string) env('SENTRY_API_BASE_URL', 'https://sentry.io'), '/'),
        'org' => env('SENTRY_ORG_SLUG'),
        // Comma-separated project slugs to include (empty = org-wide
        // issues endpoint, which the token may not allow — set them).
        'projects' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SENTRY_PROJECT_SLUGS', '')),
        ))),
        'timeout' => 10,
        // Summary cache TTL (minutes). A cached tile never blocks the
        // dashboard on a slow Sentry API; 10 min is fresher than any
        // operator's triage loop.
        'cache_minutes' => (int) env('SENTRY_SUMMARY_CACHE_MINUTES', 10),
        // How many top issues the tile lists.
        'limit' => 5,
    ],

    // ── Scheduled diagnostic sweeps (Iteration 4; cadences in 6) ──────
    //
    // Iteration 3 diagnostics are PULL: an operator clicks, they run.
    // Sweeps make the same read-only checks PUSH: ops:sweep-diagnostics
    // (every 15 minutes, via the Coolify scheduled task) probes a fixed
    // set of self-scoped diagnostics and, when one comes back degraded
    // or failed, records a control-plane event (deduplicated by the
    // ingestor — recurrence bumps the counter, it never spams rows) and
    // alerts Slack through OperationalAlertService with its own dedup
    // keys (warning TTL 2 h / error TTL 1 h). When a previously-bad
    // check comes back healthy, its event is resolved and an info-level
    // "recovered" note is sent — the loop closes itself.
    //
    // Inconclusive results are deliberately NOT events: "cannot
    // determine" is not a problem signal.
    //
    // Only self-scoped diagnostics from the allow-list can be swept
    // (application-scoped checks need a target the sweep doesn't have).
    // Unknown ids in the env var are skipped with a warning, never fatal.
    //
    // PER-CHECK CADENCE (Iteration 6): OPS_SWEEP_CADENCES throttles how
    // often each check is actually probed when it is HEALTHY, so cheap
    // checks stay at every-sweep while expensive ones run hourly:
    //
    //   OPS_SWEEP_CADENCES=server.disk:60,database.connectivity:60
    //
    // Semantics: "probe at most every N minutes while healthy". A check
    // that has an OPEN sweep event is probed EVERY sweep regardless of
    // its cadence, so recovery is always detected within one sweep
    // interval (15 min) — cadence only ever throttles the happy path.
    // Detection latency for a NEW problem equals the cadence, by design:
    // an hourly disk check finds a disk problem up to an hour late —
    // that is the trade the operator explicitly chose. Entries below the
    // 15-minute sweep interval or for unknown ids are ignored with a
    // warning. Last-probe bookkeeping lives in the cache (a flush just
    // means one extra probe — harmless).
    'sweeps' => [
        'enabled' => filter_var(env('OPS_SWEEP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'diagnostics' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'OPS_SWEEP_DIAGNOSTICS',
                'database.connectivity,redis.connectivity,queue.health,server.disk,app.scheduler',
            )),
        ))),
        // 'id' => minutes map parsed from OPS_SWEEP_CADENCES (validation of
        // the ids themselves happens in the command, which knows the
        // registry; config only parses the shape).
        'cadences' => collect(array_map('trim', explode(',', (string) env('OPS_SWEEP_CADENCES', ''))))
            ->filter(fn ($entry) => $entry !== '' && str_contains($entry, ':'))
            ->mapWithKeys(function ($entry) {
                $parts = explode(':', $entry, 2);

                return [trim((string) $parts[0]) => (int) ($parts[1] ?? 0)];
            })
            ->filter(fn ($minutes) => $minutes > 0)
            ->all(),
    ],

    // ── Viewer access / RBAC (Iteration 5; operator tier in 6) ────────
    //
    // /ops is no longer super-admin-only: a super-admin can grant a
    // REGULAR user (auth + verified + MFA required) access from
    // /ops/access. Grants live in ops_access_grants (a ledger —
    // revocation sets revoked_at, history stays). Two tiers:
    //
    //   viewer   — read-only (overview/applications/errors/incidents/
    //              diagnostic results). The Iteration-5 tier.
    //   operator — everything the viewer sees PLUS running the read-only
    //              diagnostics (POST /ops/diagnostics/run, guarded by
    //              EnsureOpsOperator at the route level). The checks are
    //              allow-listed, redacted, audited per run — delegation
    //              without blast radius. Never the Actions hub, never
    //              credentials, never access management.
    //
    // The READ/WRITE split is enforced at the ROUTE level
    // (routes/web.php): every POST outside the diagnostics-run surface,
    // the Actions hub, the Credentials page and the Access page remain
    // super-admin-only. Level changes (viewer ↔ operator) revoke +
    // re-grant atomically — the ledger keeps both rows.
    //
    // Kill switches (independent, instant, delete nothing):
    //   OPS_VIEWER_ACCESS_ENABLED=false   fail-closes every viewer grant.
    //   OPS_OPERATOR_ACCESS_ENABLED=false fail-closes every operator grant.
    // Super-admins are unaffected by either — their access never came
    // from a grant. Incident-response levers, not data operations:
    // flipping back on restores the grants untouched.
    'access' => [
        'viewer_enabled' => filter_var(env('OPS_VIEWER_ACCESS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'operator_enabled' => filter_var(env('OPS_OPERATOR_ACCESS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    // ── Credential inventory (Iteration 5) ─────────────────────────────
    //
    // The §15 rotation checklist, made live on /ops/credentials: which
    // credential surfaces are configured (PRESENCE booleans only — a
    // value never leaves the environment), when each was last rotated
    // (the ops_credentials ledger) and what to do next (per-credential
    // cadence + guidance). Catalog and cadences are code constants in
    // OpsCredentialInventoryService — the catalog IS the documentation,
    // and changing it means changing that class + its tests (same rule
    // as the health-score formula).
    'credentials' => [
        // Rotation cadences are per-credential constants in the catalog
        // (90 days for API keys, 180 for webhooks/secrets, APP_KEY
        // policy-driven). This switch only gates the PAGE — recording a
        // rotation is a pure ledger write; there is nothing to fail-close.

        // ROTATION REMINDERS (Iteration 6): ops:sweep-credentials (daily,
        // 09:00) makes cadence lapses find the operator instead of
        // waiting for a visit to the page — the same
        // "problems find the operator" philosophy as the diagnostic
        // sweep. ROTATE NOW / OVERDUE chips → one warning Slack alert
        // (deduplicated daily) + one deduplicated SECURITY event that
        // resolves itself when the page is worked; DUE SOON only → a
        // gentle weekly info nudge. Kill switch below; the page itself
        // is never gated by it.
        'reminders_enabled' => filter_var(env('OPS_CREDENTIAL_REMINDERS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    // ── Morning digest (Iteration 7) ─────────────────────────────────────
    //
    // ONE Slack message a day (08:15) that unifies every domain the
    // control plane watches: health score + verdict caps, active
    // incidents, untriaged errors, the application rollup + worst
    // offenders, the autonomous sweep's open findings, backup
    // freshness, the billing-webhook ledger, the Sentry 24 h trend
    // (omitted when the API token is not configured), credential
    // rotation cadence and the last 24 h of operator activity
    // (diagnostic runs by actor + audited ops.* actions).
    //
    // THE SILENCE CONTRACT (§16.4): alerts fire on problems; the digest
    // fires on TIME. While enabled it sends every day — including the
    // boring all-quiet mornings — so a silent morning becomes a signal
    // in itself (the dead-man's-switch rule). The scheduled send is
    // deduplicated within the alert service's 6 h info TTL; a manual
    // "send now" from /ops/digest (super-admin, audited) deliberately
    // bypasses the dedup so a test send can never silently disappear.
    //
    // The switch gates the SCHEDULED send only — the /ops/digest
    // preview page and the manual button keep working with it off.
    'digest' => [
        'enabled' => filter_var(env('OPS_MORNING_DIGEST_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // ── Delivery watchdog (Iteration 8) ────────────────────────────
        // Meta-monitoring the monitor: 30 minutes after the 08:15 send
        // (daily 08:45), verify the ops:morning-digest:last stamp is from
        // TODAY. A missing/stale stamp while the digest is enabled means
        // the silence contract (§16.4) is broken — stale scheduler, a
        // throwing send path, or a flipped switch — so the watchdog
        // raises the alarm itself: ONE warning Slack alert (dedup key
        // ops.digest.missed) + ONE deduplicated INFRASTRUCTURE event
        // (source 'watchdog') that auto-resolves with a single recovery
        // note the next healthy morning. Quiet when healthy, exactly
        // like every other monitor. No-op while the digest itself is
        // disabled (a suspended contract cannot be broken).
        'watchdog_enabled' => filter_var(env('OPS_DIGEST_WATCHDOG_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    // ── Weekly review (Iteration 8) ──────────────────────────────────
    //
    // The Monday deep-dive (08:30, inside the morning-briefing block):
    // trailing-7-day trends the daily cadence cannot show — error
    // volume by category, incident throughput with MTTA/MTTR,
    // deployment activity + failures, the sweep's finding history,
    // current backup freshness and the week's operator activity.
    //
    // NOT a dead-man's switch (the daily digest + the watchdog carry
    // the silence contract): this is informational, so the switch just
    // turns it off — nothing is suspended. The /ops/digest preview and
    // the manual send button keep working with it off. Scheduled send
    // deduplicated within the alert service's 6 h info TTL.
    'weekly_review' => [
        'enabled' => filter_var(env('OPS_WEEKLY_REVIEW_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
