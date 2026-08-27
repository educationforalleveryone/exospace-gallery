<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics;

use App\Ops\Diagnostics\Runners\ApplicationDiagnostics;
use App\Ops\Diagnostics\Runners\ContainerDiagnostics;
use App\Ops\Diagnostics\Runners\DatabaseDiagnostics;
use App\Ops\Diagnostics\Runners\DeploymentDiagnostics;
use App\Ops\Diagnostics\Runners\QueueDiagnostics;
use App\Ops\Diagnostics\Runners\RedisDiagnostics;
use App\Ops\Diagnostics\Runners\ServerDiagnostics;
use App\Ops\Support\ErrorClassifier;

/**
 * OpsCenter — DiagnosticRegistry (Iteration 3).
 *
 * THE allow-list. A diagnostic exists if and only if it is declared here —
 * the engine rejects anything else. This is the "no arbitrary command
 * execution" guarantee from the brief: there is no free-form diagnostic
 * language anywhere; the surface is a fixed catalog of read-only checks,
 * each backed by a hard-coded PHP runner.
 *
 * The ids are the SAME ids ErrorClassifier recommends on classified events
 * (enforced by OpsDiagnosticRegistryTest via reflection, so a classifier rule
 * can never recommend a diagnostic that doesn't exist).
 *
 * Scope:
 *   self        — inspects the control plane's own subsystems (its database,
 *                 Redis, queue, filesystem, scheduler). Running it against
 *                 another application returns an honest inconclusive result.
 *   application — meaningful per application (defaults to the control plane
 *                 itself when no target is given).
 */
final class DiagnosticRegistry
{
    /**
     * Scope: the check applies to the control plane host only.
     */
    public const SCOPE_SELF = 'self';

    /**
     * Scope: the check is per-application (self when no target given).
     */
    public const SCOPE_APPLICATION = 'application';

    /**
     * @var array<string, array{label: string, group: string, description: string, scope: string, runner: string}>
     */
    private const DIAGNOSTICS = [
        // ── Database ──────────────────────────────────────────────────────
        'database.connectivity' => [
            'label' => 'Database connectivity',
            'group' => 'Database',
            'description' => 'Can the control plane reach its database? Distinguishes server-down from wrong credentials from unknown database.',
            'scope' => self::SCOPE_SELF,
            'runner' => DatabaseDiagnostics::class,
        ],
        'database.health' => [
            'label' => 'Database health',
            'group' => 'Database',
            'description' => 'Connectivity plus schema sanity (core tables present) and recent database/migration errors.',
            'scope' => self::SCOPE_SELF,
            'runner' => DatabaseDiagnostics::class,
        ],
        'database.connection-health' => [
            'label' => 'Database connection pool',
            'group' => 'Database',
            'description' => 'Connection utilization against max_connections — detects pool exhaustion and leaked connections.',
            'scope' => self::SCOPE_SELF,
            'runner' => DatabaseDiagnostics::class,
        ],
        'database.migration-status' => [
            'label' => 'Migration status',
            'group' => 'Database',
            'description' => 'Are migrations pending? Did a migration fail recently? Is the running code ahead of the schema? Read-only — never auto-runs migrations.',
            'scope' => self::SCOPE_SELF,
            'runner' => DatabaseDiagnostics::class,
        ],

        // ── Redis / Cache ─────────────────────────────────────────────────
        'redis.connectivity' => [
            'label' => 'Redis connectivity & latency',
            'group' => 'Cache & Queue',
            'description' => 'Round-trip probe of Redis, latency measurement and memory pressure where the server reports it.',
            'scope' => self::SCOPE_SELF,
            'runner' => RedisDiagnostics::class,
        ],
        'app.cache' => [
            'label' => 'Application cache',
            'group' => 'Cache & Queue',
            'description' => 'Write/read/delete round-trip through the configured cache store (sessions and queues depend on it).',
            'scope' => self::SCOPE_SELF,
            'runner' => ApplicationDiagnostics::class,
        ],

        // ── Queue / Workers ───────────────────────────────────────────────
        'queue.health' => [
            'label' => 'Queue & worker health',
            'group' => 'Cache & Queue',
            'description' => 'Pending backlog, oldest waiting job, failed-job counts and scheduled-job heartbeats.',
            'scope' => self::SCOPE_SELF,
            'runner' => QueueDiagnostics::class,
        ],
        'queue.failed-jobs' => [
            'label' => 'Failed jobs',
            'group' => 'Cache & Queue',
            'description' => 'Which jobs are failing, how often, since when — grouped by job and queue.',
            'scope' => self::SCOPE_SELF,
            'runner' => QueueDiagnostics::class,
        ],

        // ── Containers & Deployments ──────────────────────────────────────
        'container.health' => [
            'label' => 'Container health',
            'group' => 'Containers & Deployments',
            'description' => 'Live container status from the Coolify API (running:healthy / exited / restarting) plus recent container events.',
            'scope' => self::SCOPE_APPLICATION,
            'runner' => ContainerDiagnostics::class,
        ],
        'container.recent-logs' => [
            'label' => 'Recent logs',
            'group' => 'Containers & Deployments',
            'description' => 'Tail of the application logs (redacted) or — for non-self apps — the errors the control plane has captured.',
            'scope' => self::SCOPE_APPLICATION,
            'runner' => ContainerDiagnostics::class,
        ],
        'deployment.recent' => [
            'label' => 'Recent deployments',
            'group' => 'Containers & Deployments',
            'description' => 'Last deployments with status, commit and duration — failed deployments link to their events.',
            'scope' => self::SCOPE_APPLICATION,
            'runner' => DeploymentDiagnostics::class,
        ],

        // ── Server ────────────────────────────────────────────────────────
        'server.disk' => [
            'label' => 'Disk usage',
            'group' => 'Server',
            'description' => 'Persistent-volume usage with the same 80%/90% thresholds the alerting service uses.',
            'scope' => self::SCOPE_SELF,
            'runner' => ServerDiagnostics::class,
        ],
        'server.resources' => [
            'label' => 'Server resources',
            'group' => 'Server',
            'description' => 'Load, memory, uptime and PHP runtime as seen from inside the container — host-wide figures live in Coolify.',
            'scope' => self::SCOPE_SELF,
            'runner' => ServerDiagnostics::class,
        ],

        // ── Application ───────────────────────────────────────────────────
        'app.health' => [
            'label' => 'Application health',
            'group' => 'Application',
            'description' => 'The full subsystem rollup for the control plane host; for other applications, a bounded HTTP probe of their health endpoint.',
            'scope' => self::SCOPE_APPLICATION,
            'runner' => ApplicationDiagnostics::class,
        ],
        'app.recent-errors' => [
            'label' => 'Recent errors',
            'group' => 'Application',
            'description' => 'What the control plane has seen from this application: counts by severity and the currently-active problems.',
            'scope' => self::SCOPE_APPLICATION,
            'runner' => ApplicationDiagnostics::class,
        ],
        'app.filesystem' => [
            'label' => 'Filesystem & storage',
            'group' => 'Application',
            'description' => 'Write probes on the persistent storage paths and the logs directory.',
            'scope' => self::SCOPE_SELF,
            'runner' => ApplicationDiagnostics::class,
        ],
        'app.scheduler' => [
            'label' => 'Scheduler & scheduled jobs',
            'group' => 'Application',
            'description' => 'Freshness of the Coolify scheduled-task heartbeat (scheduler.log) and every monitored job cadence.',
            'scope' => self::SCOPE_SELF,
            'runner' => ApplicationDiagnostics::class,
        ],
    ];

    /**
     * Does the id exist in the allow-list?
     */
    public static function has(string $id): bool
    {
        return isset(self::DIAGNOSTICS[$id]);
    }

    /**
     * @return array{label: string, group: string, description: string, scope: string, runner: string}|null
     */
    public static function get(string $id): ?array
    {
        return self::DIAGNOSTICS[$id] ?? null;
    }

    /**
     * All definitions keyed by id (for the catalog UI).
     *
     * @return array<string, array{label: string, group: string, description: string, scope: string, runner: string}>
     */
    public static function all(): array
    {
        return self::DIAGNOSTICS;
    }

    /**
     * Group labels in display order (catalog UI groups cards under these).
     *
     * @return array<int, string>
     */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::DIAGNOSTICS as $definition) {
            if (! in_array($definition['group'], $groups, true)) {
                $groups[] = $definition['group'];
            }
        }

        return $groups;
    }

    /**
     * Every diagnostic id the ErrorClassifier may recommend on classified
     * events. Used by tests to guarantee the classifier and the engine can
     * never drift apart — a recommended chip that isn't runnable is a broken
     * promise to the operator.
     *
     * @return array<int, string>
     */
    public static function classifierRecommendedIds(): array
    {
        return ErrorClassifier::recommendedDiagnosticIds();
    }

    /**
     * Human label for an id (UI fallback).
     */
    public static function label(string $id): string
    {
        return self::DIAGNOSTICS[$id]['label'] ?? $id;
    }
}
