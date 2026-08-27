<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Services\JobHeartbeatService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * OpsCenter — OpsHealthService.
 *
 * Computes the platform + per-application health rollups shown on the
 * dashboard. This is an AGGREGATOR, not a new monitoring system (ADR-6):
 * every signal either already exists (JobHeartbeatService, failed_jobs,
 * backup freshness, /health checks) or comes from ops_events produced by
 * the ingestors.
 *
 * Output shape (both methods):
 *   status  : 'healthy' | 'degraded' | 'critical' | 'unknown'
 *   reasons : string[] — human sentences explaining WHY, in priority order.
 *             The UI renders these verbatim under the status; a status
 *             without reasons is a bug (the whole point is "healthy BECAUSE
 *             ..." / "degraded BECAUSE ...").
 *
 * We deliberately do NOT compute a numeric health score in Iteration 1 —
 * an unexplained number is worse than a labeled status. If a score is ever
 * added, its formula will live in this class and be documented in the
 * master manual (brief requirement: no meaningless numbers).
 */
class OpsHealthService
{
    /**
     * The platform-wide rollup (worst of all applications + self checks).
     *
     * @return array{status: string, reasons: string[]}
     */
    public function platformHealth(): array
    {
        $reasons = [];
        $status = 'healthy';

        // ── Self subsystems (reuse the /health logic, read-only) ───────
        $self = $this->selfChecks();
        foreach ($self['reasons'] as $reason) {
            $reasons[] = $reason;
        }
        $status = $this->worst($status, $self['status']);

        // ── Applications (from the Coolify sync) ───────────────────────
        $apps = OpsApplication::whereNot('kind', 'server')->get();
        $stopped = $apps->where('health', 'stopped')->count();
        $degraded = $apps->where('health', 'degraded')->count();
        $running = $apps->where('health', 'running')->count();

        if ($stopped > 0) {
            $names = $apps->where('health', 'stopped')->pluck('name')->take(3)->implode(', ');
            $reasons[] = "{$stopped} application(s) stopped ({$names})";
            $status = $this->worst($status, 'critical');
        }

        if ($degraded > 0) {
            $names = $apps->where('health', 'degraded')->pluck('name')->take(3)->implode(', ');
            $reasons[] = "{$degraded} application(s) degraded ({$names})";
            $status = $this->worst($status, 'degraded');
        }

        if ($apps->count() > 0 && count($reasons) === 0) {
            $reasons[] = "{$running} of {$apps->count()} applications reporting healthy";
        }

        if ($apps->count() === 0) {
            $reasons[] = 'No applications synced from Coolify yet — waiting for the first platform sync';
            $status = $this->worst($status, 'unknown');
        }

        // ── Open critical/error events anywhere on the platform ────────
        $criticalEvents = OpsEvent::whereIn('status', ['open', 'acknowledged'])
            ->whereIn('severity', ['critical', 'error'])
            ->count();

        if ($criticalEvents > 0) {
            $reasons[] = "{$criticalEvents} unresolved error-level event(s) in the control plane";
            $status = $this->worst($status, 'degraded');
        }

        if ($status === 'healthy' && count($reasons) === 0) {
            $reasons[] = 'All monitored subsystems healthy';
        }

        return ['status' => $status, 'reasons' => $reasons];
    }

    /**
     * Host-application (Exospace) subsystem checks. Mirrors
     * HealthController's checks (DB, cache, queue, storage) plus the
     * operational monitors that already exist (heartbeats, backups,
     * scheduler freshness) — all read-only.
     *
     * @return array{status: string, reasons: string[]}
     */
    public function selfChecks(): array
    {
        $reasons = [];
        $status = 'healthy';

        // Database
        try {
            DB::select('SELECT 1');
        } catch (Throwable) {
            $reasons[] = 'Database unreachable';
            $status = 'critical';

            // Everything else below depends on the DB or is less important —
            // return early with what we know.
            return ['status' => $status, 'reasons' => $reasons];
        }

        // Cache / Redis
        try {
            $probe = 'ops:health:'.uniqid('', true);
            Cache::put($probe, 'ok', 10);
            $ok = Cache::get($probe) === 'ok';
            Cache::forget($probe);

            if (! $ok) {
                $reasons[] = 'Cache (Redis) write/read probe failed';
                $status = 'degraded';
            }
        } catch (Throwable) {
            $reasons[] = 'Cache (Redis) unreachable';
            $status = $this->worst($status, 'critical');
        }

        // Queue: failed jobs backlog (thresholds mirror OperationalAlertService)
        try {
            $failed = DB::table('failed_jobs')->count();
            if ($failed > 50) {
                $reasons[] = "{$failed} failed jobs in the queue (critical threshold: 50)";
                $status = $this->worst($status, 'critical');
            } elseif ($failed > 10) {
                $reasons[] = "{$failed} failed jobs in the queue (warning threshold: 10)";
                $status = $this->worst($status, 'degraded');
            }
        } catch (Throwable) {
            // failed_jobs table absent (pre-migration) — skip silently.
        }

        // Queue backlog: oldest pending job age
        try {
            $oldest = DB::table('jobs')->orderBy('id')->first();
            if ($oldest !== null && (time() - (int) ($oldest->available_at ?? time())) > 600) {
                $reasons[] = 'Oldest queued job has been waiting more than 10 minutes — workers may be down';
                $status = $this->worst($status, 'critical');
            }
        } catch (Throwable) {
            // jobs table absent — skip.
        }

        // Scheduler freshness (the Coolify scheduled task touches scheduler.log)
        try {
            $schedulerLog = storage_path('logs/scheduler.log');
            if (is_file($schedulerLog)) {
                $ageMinutes = (time() - (int) filemtime($schedulerLog)) / 60;
                if ($ageMinutes > 10) {
                    $reasons[] = sprintf('Scheduler log is %.0f minutes old — the scheduled task may have stopped', $ageMinutes);
                    $status = $this->worst($status, 'critical');
                }
            }
        } catch (Throwable) {
            // Filesystem check failure — disk check below will surface it.
        }

        // Scheduled-job heartbeats (reuses JobHeartbeatService)
        try {
            $heartbeats = app(JobHeartbeatService::class);
            $stale = [];
            foreach (JobHeartbeatService::MONITORED_JOBS as $job => $maxAge) {
                if ($heartbeats->status($job) === 'stale') {
                    $stale[] = $job;
                }
            }
            if ($stale !== []) {
                $reasons[] = 'Scheduled jobs missed their cadence: '.implode(', ', array_slice($stale, 0, 3));
                $status = $this->worst($status, 'degraded');
            }
        } catch (Throwable) {
            // Cache-backed; if unreachable the cache probe already flagged it.
        }

        // Backup freshness (mirrors OperationalAlertService::checkBackupHealth logic)
        try {
            $staleBackupDisks = [];
            $disks = config('backup.backup.destination.disks', ['local']);
            $disks = is_array($disks) ? $disks : [$disks];

            foreach ($disks as $diskName) {
                $disk = Storage::disk($diskName);
                $files = array_filter(
                    $disk->files((string) config('backup.backup.name', 'Laravel-backup')),
                    fn ($f) => str_ends_with($f, '.zip'),
                );

                if ($files === []) {
                    $staleBackupDisks[] = "{$diskName} (no backups found)";

                    continue;
                }

                $newest = 0;
                foreach ($files as $file) {
                    $newest = max($newest, (int) $disk->lastModified($file));
                }

                if ((time() - $newest) > 26 * 3600) {
                    $staleBackupDisks[] = sprintf('%s (newest backup %.1f hours old)', $diskName, (time() - $newest) / 3600);
                }
            }

            if ($staleBackupDisks !== []) {
                $reasons[] = 'Backup problem on: '.implode('; ', $staleBackupDisks);
                $status = $this->worst($status, 'critical');
            }
        } catch (Throwable) {
            // Backup disks unreadable — flagged by the scheduled alert path.
        }

        // Disk pressure (same thresholds as OperationalAlertService)
        try {
            $path = Storage::disk('public')->path('');
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);
            if ($free !== false && $total !== false && $total > 0) {
                $usedPct = (1 - $free / $total) * 100;
                if ($usedPct > 90) {
                    $reasons[] = sprintf('Disk usage %.1f%% — under 10%% free', $usedPct);
                    $status = $this->worst($status, 'critical');
                } elseif ($usedPct > 80) {
                    $reasons[] = sprintf('Disk usage %.1f%% — plan cleanup', $usedPct);
                    $status = $this->worst($status, 'degraded');
                }
            }
        } catch (Throwable) {
            // skip
        }

        return ['status' => $status, 'reasons' => $reasons];
    }

    /**
     * Per-application status with reasons, for the overview grid.
     *
     * @return \Illuminate\Support\Collection<int, array{application: OpsApplication, status: string, reasons: string[]}>
     */
    public function applicationStatuses(): \Illuminate\Support\Collection
    {
        $result = collect();

        foreach (OpsApplication::orderByDesc('is_self')->orderBy('name')->get() as $app) {
            $reasons = [];
            $status = match ($app->health) {
                'running' => 'healthy',
                'degraded' => 'degraded',
                'stopped' => 'critical',
                default => 'unknown',
            };

            $openEvents = OpsEvent::where('ops_application_id', $app->id)
                ->whereIn('status', ['open', 'acknowledged'])
                ->whereIn('severity', ['critical', 'error'])
                ->count();

            if ($openEvents > 0) {
                $reasons[] = "{$openEvents} unresolved error-level event(s)";
                $status = $this->worst($status, 'degraded');
            }

            if ($reasons === []) {
                $reasons[] = match ($status) {
                    'healthy' => $app->status !== '' ? 'Coolify status: '.$app->status : 'No problems detected',
                    'critical' => 'Not running ('.$app->status.')',
                    'degraded' => 'Coolify status: '.$app->status,
                    default => 'No sync data yet — waiting for the next platform sync',
                };
            }

            $result->push(['application' => $app, 'status' => $status, 'reasons' => $reasons]);
        }

        return $result;
    }

    private function worst(string $a, string $b): string
    {
        $rank = ['unknown' => 0, 'healthy' => 1, 'degraded' => 2, 'critical' => 3];

        return ($rank[$a] ?? 1) >= ($rank[$b] ?? 1) ? $a : $b;
    }
}
