<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A-10 FIX (Iter-006): Operational alerting service.
 *
 * Sends alerts for operational events that require human attention:
 *   - Failed jobs exceeding threshold
 *   - Scheduler not running (stale scheduler.log)
 *   - Disk usage exceeding threshold
 *   - Sentry error spikes (detected externally)
 *   - Coolify API unreachable
 *
 * Alerts are sent via the configured webhook URL (Slack, PagerDuty, Discord).
 * The webhook URL is configured via the OPERATIONAL_ALERT_WEBHOOK env var.
 *
 * If no webhook is configured, alerts are logged at CRITICAL level —
 * Sentry picks them up (if Sentry is configured).
 *
 * Usage:
 *   app(OperationalAlertService::class)->alert(
 *       'Queue backup detected',
 *       'Failed jobs: 25 (threshold: 10)',
 *       'warning'
 *   );
 */
class OperationalAlertService
{
    public function alert(string $title, string $message, string $severity = 'warning'): void
    {
        $webhookUrl = config('services.operational_alerts.webhook_url');

        $emoji = match ($severity) {
            'critical' => '🔴',
            'error'    => '🟠',
            'warning'  => '🟡',
            'info'     => '🔵',
            default    => '⚪',
        };

        $payload = [
            // 'text' is required for Slack's legacy Incoming Webhooks to
            // render anything at all — without it, Slack accepts the POST
            // (200 OK) but displays nothing in the channel.
            'text' => sprintf(
                "%s *%s* [%s/%s]\n%s",
                $emoji,
                $title,
                app()->environment(),
                strtoupper($severity),
                $message
            ),

            // Kept for any future non-Slack endpoint (PagerDuty, custom
            // receiver, etc.) that may want the structured fields instead.
            'title'    => $title,
            'message'  => $message,
            'severity' => $severity,
            'source'   => 'exospace',
            'environment' => app()->environment(),
            'timestamp' => now()->toIso8601String(),
        ];

        if ($webhookUrl) {
            try {
                Http::timeout(10)->post($webhookUrl, $payload);
            } catch (\Throwable $e) {
                Log::critical('OperationalAlertService: failed to send webhook alert', [
                    'title'   => $title,
                    'message' => $message,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // Always log at the appropriate level (Sentry picks this up)
        $level = match ($severity) {
            'critical' => 'critical',
            'error'    => 'error',
            'warning'  => 'warning',
            'info'     => 'info',
            default    => 'warning',
        };

        Log::{$level}("OperationalAlert: {$title}", [
            'message'  => $message,
            'severity' => $severity,
        ]);
    }

    /**
     * Check operational health and fire alerts if thresholds are exceeded.
     * Called by a scheduled command (e.g. every 5 minutes).
     *
     * ITERATION-6 (AUDIT-P1-6.2): Added checkQueueWorkerHealth().
     * ITERATION-6 (AUDIT-P1-6.4): Added checkBackupHealth().
     */
    public function checkAndAlert(): void
    {
        $this->checkFailedJobs();
        $this->checkDiskUsage();
        $this->checkSchedulerHealth();
        $this->checkQueueWorkerHealth();
        $this->checkBackupHealth();
    }

    private function checkFailedJobs(): void
    {
        try {
            $failedCount = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();

            if ($failedCount > 50) {
                $this->alert(
                    'Queue backup detected',
                    "Failed jobs: {$failedCount} (threshold: 50). Check the queue worker and failed_jobs table.",
                    'critical'
                );
            } elseif ($failedCount > 10) {
                $this->alert(
                    'Queue warning: failed jobs accumulating',
                    "Failed jobs: {$failedCount} (threshold: 10). Monitor the queue.",
                    'warning'
                );
            }
        } catch (\Throwable $e) {
            // DB error — can't check failed_jobs. Don't alert (the health check
            // endpoint will catch DB errors).
        }
    }

    private function checkDiskUsage(): void
    {
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            $diskPath = $disk->path('');
            $freeBytes = @disk_free_space($diskPath);
            $totalBytes = @disk_total_space($diskPath);

            if ($freeBytes !== false && $totalBytes !== false && $totalBytes > 0) {
                $usedPct = (1 - $freeBytes / $totalBytes) * 100;

                if ($usedPct > 90) {
                    $this->alert(
                        'Disk space critical',
                        sprintf('Disk usage: %.1f%% — less than 10%% free. Clean up storage immediately.', $usedPct),
                        'critical'
                    );
                } elseif ($usedPct > 80) {
                    $this->alert(
                        'Disk space warning',
                        sprintf('Disk usage: %.1f%% — less than 20%% free. Plan cleanup.', $usedPct),
                        'warning'
                    );
                }
            }
        } catch (\Throwable $e) {
            // Can't check disk — skip
        }
    }

    private function checkSchedulerHealth(): void
    {
        // Check if the scheduler log has been written to recently.
        // The scheduler loop in docker-start.sh writes to scheduler.log every 60s.
        // If the log hasn't been updated in >5 minutes, the scheduler is likely dead.
        $logPath = storage_path('logs/scheduler.log');

        if (! file_exists($logPath)) {
            // Log doesn't exist — scheduler hasn't run yet (fresh install) or
            // the log path is wrong. Don't alert on fresh installs.
            return;
        }

        $lastModified = @filemtime($logPath);

        if ($lastModified === false) {
            return;
        }

        $ageMinutes = (time() - $lastModified) / 60;

        if ($ageMinutes > 5) {
            $this->alert(
                'Scheduler appears to be down',
                sprintf('scheduler.log last updated %.0f minutes ago — the scheduler loop may have died. Check docker-start.sh and container logs.', $ageMinutes),
                'critical'
            );
        }
    }

    /**
     * ITERATION-6 (AUDIT-P1-6.2): Check queue worker health.
     *
     * The queue worker (started by docker-start.sh) doesn't write to a log
     * file by default — its stdout/stderr is captured by Coolify's log driver.
     * However, if the worker dies, the failed_jobs table will start accumulating
     * (caught by checkFailedJobs above) AND the jobs table will stop draining.
     *
     * This check looks at the `jobs` table — if jobs have been sitting in the
     * queue for >10 minutes without being processed, the worker is likely dead
     * (even if failed_jobs hasn't crossed the threshold yet). This catches the
     * "worker silently died without producing failed jobs" case (e.g. OOM kill
     * before the job could throw).
     *
     * The check is defensive: if the `jobs` table doesn't exist (SQLite in
     * tests) or the query fails, it skips silently. The existing
     * checkFailedJobs() + checkSchedulerHealth() provide redundant coverage.
     */
    private function checkQueueWorkerHealth(): void
    {
        try {
            // Find the oldest job in the queue. If it's been waiting >10 min,
            // the worker is likely not processing jobs.
            $oldestJob = \Illuminate\Support\Facades\DB::table('jobs')
                ->orderBy('id')
                ->first();

            if ($oldestJob === null) {
                // No jobs in the queue — worker is healthy (or no traffic).
                return;
            }

            // The `jobs` table has `available_at` (unix timestamp when the job
            // becomes available for processing) and `reserved_at` (null if
            // not currently being processed).
            $availableAt = (int) ($oldestJob->available_at ?? 0);
            $ageSeconds = time() - $availableAt;

            if ($ageSeconds > 600) { // >10 minutes
                $this->alert(
                    'Queue worker may be down',
                    sprintf(
                        'Oldest job in the queue has been waiting %.0f minutes (threshold: 10 min). The queue worker may have died. Check container logs and restart if needed.',
                        $ageSeconds / 60
                    ),
                    'critical'
                );
            }
        } catch (\Throwable $e) {
            // DB error or table doesn't exist — skip. The checkFailedJobs()
            // method provides redundant coverage via the failed_jobs table.
        }
    }

    /**
     * ITERATION-6 (AUDIT-P1-6.4): Check backup health.
     *
     * The spatie/laravel-backup package sends email notifications on failure,
     * but for a premium SaaS, backup failures should ALSO appear in the same
     * Slack channel as other operational alerts (queue backup, disk full,
     * scheduler down). This check runs every 5 minutes as part of the
     * existing checkAndAlert() schedule.
     *
     * Logic:
     *   - Scans the backup destination disk (config: 'backup.backup.destination.disks[0]')
     *     for backup zip files.
     *   - If NO backup files exist at all → critical alert (backups have never run
     *     or the disk is misconfigured).
     *   - If the newest backup is older than 26 hours → critical alert (the daily
     *     1am backup didn't run, or ran and failed silently). 26 hours gives a
     *     1-hour buffer past the 24-hour daily schedule.
     *   - Otherwise: healthy (no alert).
     *
     * The check is defensive: if the disk doesn't exist or can't be read, it
     * skips silently (the existing checkDiskUsage() provides redundant coverage
     * for disk-level issues).
     */
    private function checkBackupHealth(): void
    {
        try {
            $diskName = config('backup.backup.destination.disks.0', 'local');
            $disk = \Illuminate\Support\Facades\Storage::disk($diskName);

            // Spatie backups are stored under a folder named after the app
            // (config: 'backup.backup.name', default: "{APP_NAME} Backup").
            // The folder contains zip files like "2026-08-21-01-00-00.zip".
            $backupName = config('backup.backup.name', config('APP_NAME', 'Laravel') . ' Backup');
            $backupPath = $backupName;

            // List all files in the backup directory.
            $files = $disk->files($backupPath);

            // Filter to .zip files only (Spatie may also write .sql files
            // temporarily during backup creation — those aren't complete backups).
            $zipFiles = array_filter($files, fn ($file) => str_ends_with($file, '.zip'));

            if (empty($zipFiles)) {
                $this->alert(
                    'No backups found',
                    "No backup zip files found on disk '{$diskName}' under '{$backupPath}'. Backups may have never run, or the backup destination is misconfigured. Check the spatie/laravel-backup schedule + the BACKUP_PASSWORD env var.",
                    'critical'
                );
                return;
            }

            // Find the newest backup by file modification time.
            $newestTime = 0;
            $newestFile = '';
            foreach ($zipFiles as $zipFile) {
                $modified = $disk->lastModified($zipFile);
                if ($modified > $newestTime) {
                    $newestTime = $modified;
                    $newestFile = $zipFile;
                }
            }

            $ageHours = (time() - $newestTime) / 3600;

            if ($ageHours > 26) {
                $this->alert(
                    'Backup is stale',
                    sprintf(
                        "Newest backup is %.1f hours old (threshold: 26 hours). File: %s. The daily 1am backup may have failed. Check the spatie/laravel-backup logs and the backup:run schedule.",
                        $ageHours,
                        basename($newestFile)
                    ),
                    'critical'
                );
            }
        } catch (\Throwable $e) {
            // Disk doesn't exist or can't be read — skip. The existing
            // checkDiskUsage() provides redundant coverage.
        }
    }
}