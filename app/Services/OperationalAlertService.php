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
 * ITERATION-7 (AUDIT-P1-7.1): Alert deduplication. Previously a persistent
 * condition (e.g. disk at 91%) fired a Slack alert every 5 minutes forever —
 * noise that trains operators to ignore the channel. Now callers can pass a
 * `dedupKey` to suppress repeat alerts within a severity-based TTL window:
 *   - critical: 30 min (re-alerts every 30 min if still critical)
 *   - warning:  2 hours
 *   - info:     6 hours
 *
 * Dedup is opt-in (only when `dedupKey` is non-null). Existing callers that
 * don't pass `dedupKey` behave exactly as before — no behavior change.
 *
 * ITERATION-10 (AUDIT-P1-10.1): Per-severity alert routing. Previously ALL
 * alerts went to a single webhook (OPERATIONAL_ALERT_WEBHOOK). Now each
 * severity can optionally route to its own webhook (e.g. critical →
 * PagerDuty or #exospace-critical, warning → #exospace-alerts). Falls back
 * to the default webhook when the per-severity env var is absent — fully
 * backward-compatible.
 *
 * Usage:
 *   app(OperationalAlertService::class)->alert(
 *       'Queue backup detected',
 *       'Failed jobs: 25 (threshold: 10)',
 *       'warning',
 *       'failed_jobs_warning'  // ← dedup key
 *   );
 */
class OperationalAlertService
{
    /**
     * Dedup TTLs (in seconds) per severity.
     * Critical re-alerts every 30 min so a real problem doesn't go silent.
     * Warning re-alerts every 2 hours — enough to be noticed, not spammy.
     * Info re-alerts every 6 hours.
     */
    private const DEDUP_TTL_SECONDS = [
        'critical' => 1800,  // 30 min
        'error'    => 3600,  // 1 hour
        'warning'  => 7200,  // 2 hours
        'info'     => 21600, // 6 hours
    ];

    public function alert(string $title, string $message, string $severity = 'warning', ?string $dedupKey = null): void
    {
        // ITERATION-7 (AUDIT-P1-7.1): Dedup. If a dedupKey is provided AND
        // a recent alert with the same key was sent within the TTL, skip
        // this alert. The alert is still logged at debug level so the
        // condition is traceable in logs without spamming Slack.
        if ($dedupKey !== null && $this->isRecentlyAlerted($dedupKey, $severity)) {
            Log::debug("OperationalAlertService: suppressed duplicate alert '{$title}' (dedupKey='{$dedupKey}')");
            return;
        }

        // Record that we sent this alert so future calls within the TTL are suppressed.
        if ($dedupKey !== null) {
            $this->markAlertSent($dedupKey, $severity);
        }

        // ITERATION-10 (AUDIT-P1-10.1): Per-severity webhook routing.
        // Picks the per-severity webhook if configured, falls back to the
        // default webhook. When neither is set, the alert is logged only
        // (Sentry picks up via Log::critical).
        $webhookUrl = $this->resolveWebhookUrl($severity);

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
     * ITERATION-7 (AUDIT-P1-7.1): Check if an alert with the given dedupKey
     * was sent recently (within the severity-based TTL).
     *
     * Uses the cache (Redis in production, array/file in tests). The cache
     * key is `alert:last_sent:{dedupKey}`. If the key exists, the alert
     * was recently sent and should be suppressed.
     */
    private function isRecentlyAlerted(string $dedupKey, string $severity): bool
    {
        $cacheKey = $this->dedupCacheKey($dedupKey);
        $ttl = self::DEDUP_TTL_SECONDS[$severity] ?? self::DEDUP_TTL_SECONDS['warning'];

        // Cache::has() returns true if the key exists (even with null value).
        // We store a truthy value (timestamp) so has() works correctly.
        return \Illuminate\Support\Facades\Cache::has($cacheKey);
    }

    /**
     * ITERATION-7 (AUDIT-P1-7.1): Mark that an alert was just sent, so
     * future calls with the same dedupKey within the TTL are suppressed.
     */
    private function markAlertSent(string $dedupKey, string $severity): void
    {
        $cacheKey = $this->dedupCacheKey($dedupKey);
        $ttl = self::DEDUP_TTL_SECONDS[$severity] ?? self::DEDUP_TTL_SECONDS['warning'];

        try {
            \Illuminate\Support\Facades\Cache::put($cacheKey, now()->toIso8601String(), $ttl);
        } catch (\Throwable $e) {
            // Cache unavailable (Redis down, etc.) — don't block the alert.
            // The worst case is duplicate alerts, which is better than no alerts.
            Log::debug('OperationalAlertService: cache unavailable for dedup tracking', [
                'dedupKey' => $dedupKey,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function dedupCacheKey(string $dedupKey): string
    {
        return "alert:last_sent:{$dedupKey}";
    }

    /**
     * ITERATION-10 (AUDIT-P1-10.1): Resolve the webhook URL for a given
     * severity.
     *
     * Precedence (per severity):
     *   1. The per-severity webhook (e.g. OPERATIONAL_ALERT_CRITICAL_WEBHOOK)
     *      if configured.
     *   2. The default webhook (OPERATIONAL_ALERT_WEBHOOK) as fallback.
     *   3. null if neither is set (alert is logged only — Sentry picks up
     *      via Log::critical).
     *
     * This lets the operator route critical alerts to a dedicated channel
     * (PagerDuty, #exospace-critical) while warnings stay in the general
     * channel. Fully backward-compatible — when the per-severity env vars
     * are absent, all alerts go to the default webhook (current behavior).
     */
    private function resolveWebhookUrl(string $severity): ?string
    {
        // Map severity → config key for the per-severity webhook.
        $severityConfigKey = match ($severity) {
            'critical' => 'critical_webhook_url',
            'error'    => 'error_webhook_url',
            'warning'   => 'warning_webhook_url',
            'info'     => 'info_webhook_url',
            default    => null,
        };

        // Try the per-severity webhook first.
        if ($severityConfigKey !== null) {
            $perSeverityUrl = config("services.operational_alerts.{$severityConfigKey}");
            if (is_string($perSeverityUrl) && $perSeverityUrl !== '') {
                return $perSeverityUrl;
            }
        }

        // Fall back to the default webhook.
        $defaultUrl = config('services.operational_alerts.webhook_url');
        return is_string($defaultUrl) && $defaultUrl !== '' ? $defaultUrl : null;
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
                    'critical',
                    'failed_jobs_critical' // AUDIT-P1-7.1: dedup key
                );
            } elseif ($failedCount > 10) {
                $this->alert(
                    'Queue warning: failed jobs accumulating',
                    "Failed jobs: {$failedCount} (threshold: 10). Monitor the queue.",
                    'warning',
                    'failed_jobs_warning' // AUDIT-P1-7.1: dedup key
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
                        'critical',
                        'disk_usage_critical' // AUDIT-P1-7.1: dedup key
                    );
                } elseif ($usedPct > 80) {
                    $this->alert(
                        'Disk space warning',
                        sprintf('Disk usage: %.1f%% — less than 20%% free. Plan cleanup.', $usedPct),
                        'warning',
                        'disk_usage_warning' // AUDIT-P1-7.1: dedup key
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
                'critical',
                'scheduler_stale' // AUDIT-P1-7.1: dedup key
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
    public function checkQueueWorkerHealth(): void
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
                    'critical',
                    'queue_worker_stale' // AUDIT-P1-7.1: dedup key
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
     * Slack channel as other operational alerts. This check runs every 5
     * minutes as part of the existing checkAndAlert() schedule.
     *
     * Logic:
     *   - Scans the backup destination disk(s) for backup zip files.
     *   - If NO backup files exist at all → critical alert (backups have never run
     *     or the disk is misconfigured).
     *   - If the newest backup is older than 26 hours → critical alert (the daily
     *     1am backup didn't run, or ran and failed silently). 26 hours gives a
     *     1-hour buffer past the 24-hour daily schedule.
     *   - Otherwise: healthy (no alert).
     *
     * ITERATION-9 (AUDIT-P1-9.1): Now checks ALL configured backup disks
     * (not just the first). When the operator adds 'r2' via
     * BACKUP_DISKS=local,r2, this method checks both. Each disk gets
     * its own dedup key (e.g. 'backup_none_found:local',
     * 'backup_none_found:r2') so a failure on one disk doesn't
     * suppress the alert for the other.
     *
     * The check is defensive: if a disk doesn't exist or can't be read, it
     * skips silently (the existing checkDiskUsage() provides redundant coverage
     * for disk-level issues).
     */
    public function checkBackupHealth(): void
    {
        // ITERATION-9: Get ALL configured backup disks (env-driven via BACKUP_DISKS).
        $diskNames = config('backup.backup.destination.disks', ['local']);

        // Handle both array and single-string configs (defensive).
        if (! is_array($diskNames)) {
            $diskNames = [$diskNames];
        }

        if (empty($diskNames)) {
            $diskNames = ['local'];
        }

        foreach ($diskNames as $diskName) {
            $this->checkSingleBackupDisk($diskName);
        }
    }

    /**
     * ITERATION-9 (AUDIT-P1-9.1): Check a single backup disk for health.
     * Extracted from checkBackupHealth() to support multi-disk configs.
     */
    private function checkSingleBackupDisk(string $diskName): void
    {
        try {
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
                    'critical',
                    "backup_none_found:{$diskName}" // AUDIT-P1-9.1: per-disk dedup key
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
                        "Newest backup on disk '%s' is %.1f hours old (threshold: 26 hours). File: %s. The daily 1am backup may have failed. Check the spatie/laravel-backup logs and the backup:run schedule.",
                        $diskName,
                        $ageHours,
                        basename($newestFile)
                    ),
                    'critical',
                    "backup_stale:{$diskName}" // AUDIT-P1-9.1: per-disk dedup key
                );
            }
        } catch (\Throwable $e) {
            // Disk doesn't exist or can't be read — skip. The existing
            // checkDiskUsage() provides redundant coverage.
        }
    }
}