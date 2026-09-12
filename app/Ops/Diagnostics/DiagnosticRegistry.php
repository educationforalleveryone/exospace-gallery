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

final class DiagnosticRegistry
{
    public const SCOPE_SELF = 'self';

    public const SCOPE_APPLICATION = 'application';

    /**
      * @var array<string, array{label: string, group: string, description: string, scope: string, runner: string}>
      */
    private const DIAGNOSTICS = [
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

    public static function has(string $id): bool
    {
        return isset(self::DIAGNOSTICS[$id]);
    }

    public static function get(string $id): ?array
    {
        return self::DIAGNOSTICS[$id] ?? null;
    }

    public static function all(): array
    {
        return self::DIAGNOSTICS;
    }

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

    public static function classifierRecommendedIds(): array
    {
        return ErrorClassifier::recommendedDiagnosticIds();
    }

    public static function label(string $id): string
    {
        return self::DIAGNOSTICS[$id]['label'] ?? $id;
    }
}
