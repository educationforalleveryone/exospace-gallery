<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics\Runners;

use App\Ops\Diagnostics\DiagnosticResult;
use App\Ops\Diagnostics\RunsDiagnostics;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Services\OpsHealthService;
use App\Services\JobHeartbeatService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OpsCenter — ApplicationDiagnostics (Iteration 3).
 *
 * app.health | app.recent-errors | app.filesystem | app.cache | app.scheduler
 *
 * app.health: for the control plane host, the EXISTING OpsHealthService
 * rollup (reuse, not duplication — it already aggregates DB/Redis/queue/
 * storage/heartbeats with reasons); for other applications, a bounded HTTP
 * probe of their health endpoint (only URLs the platform sync recorded —
 * no free-form URLs, so no SSRF surface).
 *
 * app.scheduler: the freshness of scheduler.log — the Coolify scheduled
 * task's heartbeat, which is THE signal that background work runs at all
 * in this deployment (the operator's own scheduled task writes it).
 *
 * Read-only: probes write only throwaway cache keys with short TTLs.
 */
class ApplicationDiagnostics implements RunsDiagnostics
{
    public function runDiagnostic(string $id, ?OpsApplication $application): DiagnosticResult
    {
        return match ($id) {
            'app.health' => $this->health($application),
            'app.recent-errors' => $this->recentErrors($application),
            'app.filesystem' => $this->filesystem(),
            'app.cache' => $this->cache(),
            'app.scheduler' => $this->scheduler(),
            default => DiagnosticResult::inconclusive(
                'Unknown application diagnostic',
                'This diagnostic id is not implemented by the application runner.',
            ),
        };
    }

    // ── app.health ──────────────────────────────────────────────────────

    private function health(?OpsApplication $application): DiagnosticResult
    {
        $target = $application ?? $this->selfApplication();

        if ($target === null) {
            return DiagnosticResult::inconclusive(
                'No application context',
                'This diagnostic needs an application (or runs against the control plane host by default). No application could be resolved.',
            );
        }

        // Self: the existing subsystem rollup (DB, cache, queue, storage,
        // heartbeats, backups, disk) — with its reasons, reused verbatim.
        if ($target->is_self) {
            try {
                $rollup = app(OpsHealthService::class)->selfChecks();
            } catch (Throwable $e) {
                return DiagnosticResult::inconclusive(
                    'Subsystem rollup failed',
                    'The health rollup could not run: '.mb_substr($e->getMessage(), 0, 200),
                    ['database.connectivity', 'redis.connectivity'],
                );
            }

            $findings = [];
            foreach ($rollup['reasons'] as $reason) {
                $findings[] = [
                    'label' => 'Subsystem check',
                    'status' => $rollup['status'] === 'critical' ? 'fail' : 'warn',
                    'detail' => $reason,
                ];
            }

            if ($findings === []) {
                $findings[] = [
                    'label' => 'All subsystem checks',
                    'status' => 'pass',
                    'detail' => 'Database, cache (Redis), queue, scheduler freshness, job heartbeats, backups and disk — all healthy.',
                ];
            }

            return DiagnosticResult::fromFindings(
                $rollup['status'] === 'healthy' ? 'All subsystems healthy' : 'Host application status: '.strtoupper($rollup['status']),
                $findings,
                $rollup['status'] === 'healthy'
                    ? 'The control plane host\'s full subsystem rollup (database, Redis, queue, scheduler, heartbeats, backups, disk) found nothing to complain about. This is the same rollup the overview page shows, with the same reasons.'
                    : 'The rollup lists exactly which subsystem(s) degraded and why — these are the same signals the overview page aggregates. The first reason is the most important one; each maps to a deeper diagnostic (database → connectivity/migration, cache → Redis, queue → queue health, scheduler → scheduler freshness).',
                ['database.connectivity', 'redis.connectivity', 'queue.health', 'app.scheduler'],
            );
        }

        // Another application: bounded HTTP probe of ITS health endpoint.
        $url = $target->url;

        if ($url === null || $url === '') {
            return DiagnosticResult::inconclusive(
                'No URL recorded for '.$target->name,
                'This application has no URL recorded by the platform sync, so it cannot be probed over HTTP. Its container status is still available via Container health (Coolify API), and its reported errors via Recent errors.',
                ['container.health', 'app.recent-errors'],
            );
        }

        $probeUrl = rtrim((string) $url, '/').'/health';
        $findings = [];

        try {
            $start = microtime(true);
            $response = Http::timeout(8)
                ->connectTimeout(5)
                ->redirect()->none() // never follow — we probe exactly the recorded host
                ->get($probeUrl);
            $ms = (int) round((microtime(true) - $start) * 1000);

            $status = $response->status();
            $body = $response->json();
            $checks = is_array($body) ? ($body['checks'] ?? null) : null;

            if ($status === 200) {
                $findings[] = [
                    'label' => 'Health endpoint probe',
                    'status' => 'pass',
                    'detail' => sprintf('%s answered 200 in %d ms.', $probeUrl, $ms),
                ];

                if (is_array($checks)) {
                    foreach (array_slice($checks, 0, 6, true) as $name => $check) {
                        $checkStatus = is_array($check) ? (string) ($check['status'] ?? 'unknown') : 'unknown';
                        $findings[] = [
                            'label' => 'Subsystem: '.(is_string($name) ? $name : 'check'),
                            'status' => $checkStatus === 'ok' ? 'pass' : (in_array($checkStatus, ['down', 'degraded'], true) ? 'fail' : 'warn'),
                            'detail' => 'Reported by the application itself: '.$checkStatus.'.',
                        ];
                    }
                }

                return DiagnosticResult::fromFindings(
                    $target->name.' is up (health endpoint 200, '.$ms.' ms)',
                    $findings,
                    'The application\'s own health endpoint answers 200 — the process is up and its self-reported subsystems (where it exposes them) are shown above. A passing health probe plus reported errors usually means partial degradation (one feature path failing), not an outage.',
                    ['app.recent-errors', 'container.health'],
                );
            }

            if ($status === 503) {
                $findings[] = [
                    'label' => 'Health endpoint probe',
                    'status' => 'fail',
                    'detail' => sprintf('%s answered 503 (service unavailable) in %d ms — the application itself declares it unhealthy.', $probeUrl, $ms),
                ];

                return DiagnosticResult::fromFindings(
                    $target->name.' declares itself UNHEALTHY (503)',
                    $findings,
                    'The application is running (it answered) but its own health check fails — it is telling the world a critical subsystem is down. Its Coolify container may flap as health probes fail and the platform restarts it. The failing subsystem is in the response body (above, when exposed) — commonly database or cache connectivity.',
                    ['container.health', 'app.recent-errors'],
                );
            }

            $findings[] = [
                'label' => 'Health endpoint probe',
                'status' => 'warn',
                'detail' => sprintf('%s answered HTTP %d in %d ms — not the expected 200/503.', $probeUrl, $status, $ms),
            ];

            return DiagnosticResult::fromFindings(
                $target->name.' answered unexpectedly (HTTP '.$status.')',
                $findings,
                'The health endpoint answered but with an unexpected status. If the application has no /health route the probe 404s — treat the container status (Coolify) as authoritative instead; Container health has it.',
                ['container.health'],
            );
        } catch (Throwable $e) {
            $findings[] = [
                'label' => 'Health endpoint probe',
                'status' => 'fail',
                'detail' => sprintf('%s could not be reached: %s', $probeUrl, mb_substr($e->getMessage(), 0, 200)),
            ];

            return DiagnosticResult::fromFindings(
                $target->name.' is unreachable over HTTP',
                $findings,
                'The HTTP probe failed — connection refused, DNS failure or timeout. Combined with the container status (run Container health) this separates the cases: container stopped = platform-side, container running but refusing connections = application-side (process hung, or wrong port).',
                ['container.health'],
            );
        }
    }

    // ── app.recent-errors ───────────────────────────────────────────────

    private function recentErrors(?OpsApplication $application): DiagnosticResult
    {
        $target = $application ?? $this->selfApplication();

        if ($target === null) {
            return DiagnosticResult::inconclusive(
                'No application context',
                'This diagnostic needs an application (or runs against the control plane host by default). No application could be resolved.',
            );
        }

        $day = OpsEvent::query()
            ->where('ops_application_id', $target->id)
            ->where('last_seen_at', '>=', now()->subDay())
            ->whereIn('status', ['open', 'acknowledged'])
            ->get();

        $week = OpsEvent::query()
            ->where('ops_application_id', $target->id)
            ->where('last_seen_at', '>=', now()->subDays(7))
            ->whereIn('status', ['open', 'acknowledged'])
            ->get();

        $criticals = $day->where('severity', 'critical');

        $findings = [
            [
                'label' => 'Last 24 hours',
                'status' => $criticals->isNotEmpty() ? 'fail' : ($day->isNotEmpty() ? 'warn' : 'pass'),
                'detail' => sprintf(
                    '%d active error(s): %d critical, %d error, %d warning/info.',
                    $day->count(),
                    $criticals->count(),
                    $day->where('severity', 'error')->count(),
                    $day->whereIn('severity', ['warning', 'info'])->count(),
                ),
            ],
            [
                'label' => 'Last 7 days',
                'status' => 'pass',
                'detail' => sprintf('%d error-level event(s) recorded in total.', $week->count()),
            ],
        ];

        // The top problems right now (the actionable part).
        $top = OpsEvent::query()
            ->where('ops_application_id', $target->id)
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByRaw('CASE severity WHEN "critical" THEN 1 WHEN "error" THEN 2 WHEN "warning" THEN 3 ELSE 4 END')
            ->orderByDesc('last_seen_at')
            ->limit(5)
            ->get();

        foreach ($top as $event) {
            $findings[] = [
                'label' => 'Active problem: #'.$event->id,
                'status' => $event->severity === 'critical' ? 'fail' : 'warn',
                'detail' => sprintf('%s — %d occurrence(s), last seen %s.', mb_substr($event->title, 0, 140), $event->occurrence_count, $event->last_seen_at?->diffForHumans() ?? 'recently'),
            ];
        }

        if ($top->isEmpty()) {
            $findings[] = [
                'label' => 'Active problems',
                'status' => 'pass',
                'detail' => 'No unresolved errors recorded for this application.',
            ];
        }

        return DiagnosticResult::fromFindings(
            $day->isEmpty()
                ? 'No active errors for '.$target->name
                : sprintf('%d active error(s) for %s (%d critical)', $day->count(), $target->name, $criticals->count()),
            $findings,
            $day->isEmpty()
                ? 'The control plane has no unresolved errors recorded for this application in the last 24 hours. Either it is genuinely healthy, or it does not report through any ingestion path (only Coolify-side status changes would be visible).'
                : 'The list above IS the current problem set for this application, worst first — each maps to an event page with likely causes and a recommended diagnostic. If the count is dominated by one fingerprint, that single root cause explains the noise (the deduplication already grouped identical errors: occurrence counts show the storm size).',
            $criticals->isNotEmpty() ? ['database.connectivity', 'redis.connectivity', 'queue.health'] : [],
        );
    }

    // ── app.filesystem ──────────────────────────────────────────────────

    private function filesystem(): DiagnosticResult
    {
        $findings = [];

        // Write probes on the persistent paths.
        $paths = [
            'storage/app (uploads & generated files)' => storage_path('app'),
            'storage/logs (application logs)' => storage_path('logs'),
        ];

        $unwritable = [];

        foreach ($paths as $label => $path) {
            try {
                $probe = $path.'/.'.uniqid('ops-diag-', true);
                $written = @file_put_contents($probe, 'ok');

                if ($written !== false) {
                    @unlink($probe);

                    $findings[] = [
                        'label' => 'Writable: '.$label,
                        'status' => 'pass',
                        'detail' => 'Write probe succeeded — '.$path,
                    ];
                } else {
                    $unwritable[] = $label;
                    $findings[] = [
                        'label' => 'Writable: '.$label,
                        'status' => 'fail',
                        'detail' => 'Write probe FAILED — '.$path.' is not writable by the PHP process (permissions or a missing volume mount).',
                    ];
                }
            } catch (Throwable $e) {
                $unwritable[] = $label;
                $findings[] = [
                    'label' => 'Writable: '.$label,
                    'status' => 'fail',
                    'detail' => 'Probe threw: '.mb_substr($e->getMessage(), 0, 150),
                ];
            }
        }

        // Public disk exists (the FILESYSTEM_DISK=public target).
        try {
            $publicExists = \Illuminate\Support\Facades\Storage::disk('public')->exists('.');
            $findings[] = [
                'label' => 'Public storage disk',
                'status' => $publicExists ? 'pass' : 'fail',
                'detail' => $publicExists
                    ? 'The public disk resolves and lists its root.'
                    : 'The public disk does NOT resolve — uploads and served files will fail (missing directory or misconfigured FILESYSTEM_DISK).',
            ];
        } catch (Throwable $e) {
            $findings[] = [
                'label' => 'Public storage disk',
                'status' => 'skip',
                'detail' => 'Could not check the public disk: '.mb_substr($e->getMessage(), 0, 150),
            ];
        }

        return DiagnosticResult::fromFindings(
            $unwritable === [] ? 'Storage paths writable' : count($unwritable).' storage path(s) NOT writable',
            $findings,
            $unwritable === []
                ? 'The persistent storage paths accept writes and the public disk resolves. Uploads, logs and generated files can be stored. (Disk SPACE is a separate question — see Disk usage.)'
                : 'At least one persistent path refuses writes. Effects: uploads fail with "unable to write" errors, or logs silently stop appearing. Causes: wrong ownership/permissions on the volume, or the volume not being mounted at the expected path in the container. Check the volume mounts in Coolify before changing permissions.',
            ['server.disk', 'container.recent-logs'],
        );
    }

    // ── app.cache ───────────────────────────────────────────────────────

    private function cache(): DiagnosticResult
    {
        $store = (string) config('cache.default');
        $findings = [];

        try {
            $start = microtime(true);
            $probe = 'ops:diag:'.uniqid('', true);
            Cache::put($probe, 'ok', 10);
            $value = Cache::get($probe);
            Cache::forget($probe);
            $ms = (int) round((microtime(true) - $start) * 1000);

            $ok = $value === 'ok';

            $findings[] = [
                'label' => 'Write/read/delete round-trip',
                'status' => $ok ? ($ms > 150 ? 'warn' : 'pass') : 'fail',
                'detail' => sprintf(
                    'Store "%s": %s in %d ms.',
                    $store,
                    $ok ? 'write, read-back and delete all succeeded' : 'the probe key did not read back correctly — the store is misbehaving',
                    $ms,
                ),
            ];
        } catch (Throwable $e) {
            $findings[] = [
                'label' => 'Write/read/delete round-trip',
                'status' => 'fail',
                'detail' => sprintf('Store "%s" probe failed: %s', $store, mb_substr($e->getMessage(), 0, 200)),
            ];

            return DiagnosticResult::fromFindings(
                'Cache store NOT working',
                $findings,
                'The cache store "'.$store.'" is failing its round-trip. In this deployment cache AND sessions AND the queue transport ride on Redis — a broken cache usually means Redis is down or rejecting the connection, and the impact is much wider than "slow pages": sessions stop working (users get logged out / errors) and queued jobs stop dispatching. Run the Redis diagnostic for the root cause.',
                ['redis.connectivity', 'queue.health'],
            );
        }

        return DiagnosticResult::fromFindings(
            'Cache store "'.$store.'" working',
            $findings,
            'The configured cache store answers reads and writes correctly. Sessions (SESSION_DRIVER=redis in this deployment) and the queue transport share this substrate, so this green light covers them too — the Redis diagnostic has the server-side detail.',
            ['redis.connectivity'],
        );
    }

    // ── app.scheduler ───────────────────────────────────────────────────

    private function scheduler(): DiagnosticResult
    {
        $findings = [];
        $stale = false;

        // 1) scheduler.log freshness — the Coolify scheduled-task heartbeat.
        $log = storage_path('logs/scheduler.log');

        if (is_file($log)) {
            $ageMinutes = (time() - (int) filemtime($log)) / 60;

            if ($ageMinutes > 10) {
                $stale = true;
            }

            $findings[] = [
                'label' => 'Scheduled-task heartbeat (scheduler.log)',
                'status' => $ageMinutes > 10 ? 'fail' : ($ageMinutes > 3 ? 'warn' : 'pass'),
                'detail' => sprintf(
                    'scheduler.log last written %.1f min ago.%s',
                    $ageMinutes,
                    $ageMinutes > 10 ? ' The Coolify scheduled task ("php artisan schedule:run >> scheduler.log") is NOT running — every scheduled job (backups, digests, cleanup, the ops sync itself) is silently stopped.' : '',
                ),
            ];
        } else {
            $stale = true;
            $findings[] = [
                'label' => 'Scheduled-task heartbeat (scheduler.log)',
                'status' => 'fail',
                'detail' => 'scheduler.log does not exist. The Coolify scheduled task has never written output on this deployment — if it was configured, it is paused or misconfigured (check Coolify → Scheduled Tasks).',
            ];
        }

        // 2) Individual job heartbeats (reuse JobHeartbeatService).
        try {
            $heartbeats = app(JobHeartbeatService::class);
            $rows = [];
            foreach (JobHeartbeatService::MONITORED_JOBS as $job => $maxAge) {
                $rows[$job] = $heartbeats->status($job);
            }

            $ok = count(array_filter($rows, fn ($s) => $s === 'ok'));
            $staleJobs = array_keys(array_filter($rows, fn ($s) => $s === 'stale'));

            $findings[] = [
                'label' => 'Monitored job cadence',
                'status' => $staleJobs !== [] ? 'warn' : 'pass',
                'detail' => sprintf(
                    '%d/%d monitored job(s) on cadence.%s',
                    $ok,
                    count($rows),
                    $staleJobs !== [] ? ' Missed: '.implode(', ', array_slice($staleJobs, 5)).'.' : '',
                ),
            ];
        } catch (Throwable $e) {
            $findings[] = [
                'label' => 'Monitored job cadence',
                'status' => 'skip',
                'detail' => 'Heartbeat store unavailable: '.mb_substr($e->getMessage(), 0, 150),
            ];
        }

        return DiagnosticResult::fromFindings(
            $stale ? 'Scheduler appears STOPPED' : 'Scheduler and monitored jobs on cadence',
            $findings,
            $stale
                ? 'The scheduled task is not running. In this deployment the scheduler runs OUTSIDE the container as a Coolify scheduled task — so the fix lives in Coolify, not in the application: check the task exists, is enabled, and look at its run history for errors. While it is stopped: backups do NOT run (backup freshness alerts will fire within hours), digests pause, cleanup pauses, and this control plane\'s own 5-minute sync stops refreshing.'
                : 'The scheduler heartbeat is fresh and the monitored jobs are on their expected cadences. Background work is executing on schedule.',
            $stale ? ['queue.health', 'container.recent-logs'] : [],
        );
    }

    private function selfApplication(): ?OpsApplication
    {
        try {
            return \App\Ops\Services\OpsEventIngestor::selfApplication();
        } catch (Throwable) {
            return null;
        }
    }
}
