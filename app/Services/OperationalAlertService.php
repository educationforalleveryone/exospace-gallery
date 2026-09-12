<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OperationalAlertService
{
    private const DEDUP_TTL_SECONDS = [
        'critical' => 1800,  // 30 min
        'error'    => 3600,  // 1 hour
        'warning'  => 7200,  // 2 hours
        'info'     => 21600, // 6 hours
    ];

    public function alert(string $title, string $message, string $severity = 'warning', ?string $dedupKey = null, bool $escalate = false): void
    {
        if ($dedupKey !== null && $this->isRecentlyAlerted($dedupKey, $severity)) {
            Log::debug("OperationalAlertService: suppressed duplicate alert '{$title}' (dedupKey='{$dedupKey}')");
            return;
        }

        // Record that we sent this alert so future calls within the TTL are suppressed.
        if ($dedupKey !== null) {
            $this->markAlertSent($dedupKey, $severity);
        }

        $webhookUrl = $this->resolveWebhookUrl($severity);

        $emoji = match ($severity) {
            'critical' => '🔴',
            'error'    => '🟠',
            'warning'  => '🟡',
            'info'     => '🔵',
            default    => '⚪',
        };

        $payload = [
            'text' => sprintf(
                "%s *%s* [%s/%s]\n%s",
                $emoji,
                $title,
                app()->environment(),
                strtoupper($severity),
                $message
            ),

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

        if ($escalate) {
            $this->postToEscalationChannel($payload);
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

    private function postToEscalationChannel(array $payload): void
    {
        $url = config('services.operational_alerts.escalation_webhook_url');
        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            Http::timeout(10)->post($url, $payload);
        } catch (\Throwable $e) {
            Log::critical('OperationalAlertService: failed to send ESCALATION webhook alert', [
                'title' => (string) ($payload['title'] ?? ''),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isRecentlyAlerted(string $dedupKey, string $severity): bool
    {
        $cacheKey = $this->dedupCacheKey($dedupKey);
        $ttl = self::DEDUP_TTL_SECONDS[$severity] ?? self::DEDUP_TTL_SECONDS['warning'];

        return \Illuminate\Support\Facades\Cache::has($cacheKey);
    }

    private function markAlertSent(string $dedupKey, string $severity): void
    {
        $cacheKey = $this->dedupCacheKey($dedupKey);
        $ttl = self::DEDUP_TTL_SECONDS[$severity] ?? self::DEDUP_TTL_SECONDS['warning'];

        try {
            \Illuminate\Support\Facades\Cache::put($cacheKey, now()->toIso8601String(), $ttl);
        } catch (\Throwable $e) {
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

    public function checkAndAlert(): void
    {
        $this->checkFailedJobs();
        $this->checkDiskUsage();
        $this->checkSchedulerHealth();
        $this->checkQueueWorkerHealth();
        $this->checkBackupHealth();
        $this->checkWebhookLedger();
        $this->checkJobHeartbeats();
    }

    public function checkJobHeartbeats(): void
    {
        $heartbeats = app(JobHeartbeatService::class);

        foreach (JobHeartbeatService::MONITORED_JOBS as $job => $maxAgeHours) {
            $status = $heartbeats->status($job);

            if ($status === 'fresh') {
                continue;
            }

            if ($status === 'stale') {
                $last = $heartbeats->lastRunAt($job);
                $ageHours = $last !== null ? round($last->diffInHours(now()), 1) : 'unknown';

                $this->alert(
                    'Scheduled job missed its cadence',
                    "{$job} last completed {$ageHours}h ago (expected at least every {$maxAgeHours}h). "
                    . 'The scheduler itself may be healthy — check this job\'s schedule entry, container logs '
                    . 'and whether an exception is thrown before it can report. '
                    . ($job === 'exospace:reconcile-subscriptions'
                        ? 'This job is the safety net for missed 2Checkout webhooks — billing drift accumulates while it is down.'
                        : ''),
                    'critical',
                    "job_heartbeat_stale:{$job}"
                );
                continue;
            }

            // 'missing' — grace period via first-observation ack.
            $heartbeats->ackMissing($job);
            $since = $heartbeats->firstObservedMissingAt($job);

            if ($since !== null && $since->addHours($maxAgeHours)->isPast()) {
                $this->alert(
                    'Scheduled job has never completed',
                    "{$job} has recorded no successful run since monitoring began "
                    . round($since->diffInHours(now()), 1) . "h ago (expected at least every {$maxAgeHours}h). "
                    . 'Verify the schedule entry exists in routes/console.php and check the container '
                    . 'logs for a start-time crash.',
                    'warning',
                    "job_heartbeat_missing:{$job}"
                );
            }
        }
    }

    public function checkWebhookLedger(): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('processed_webhooks')) {
                return; // pre-Iteration-4 schema — nothing to inspect
            }

            $failed = \Illuminate\Support\Facades\DB::table('processed_webhooks')
                ->where('status', 'failed')
                ->count();

            if ($failed > 20) {
                $this->alert(
                    'Failed webhooks piling up',
                    "{$failed} webhook(s) in the ledger are marked failed — billing events (refunds, renewals, chargebacks) are not being applied. Review and replay them at Master Control → Billing Review. (threshold: 20)",
                    'critical',
                    'webhook_ledger_failed_critical'
                );
            } elseif ($failed > 5) {
                $this->alert(
                    'Failed webhooks accumulating',
                    "{$failed} webhook(s) marked failed in the ledger. Check Master Control → Billing Review for replay. (threshold: 5)",
                    'warning',
                    'webhook_ledger_failed_warning'
                );
            }

            $stuck = \Illuminate\Support\Facades\DB::table('processed_webhooks')
                ->where('status', 'processing')
                ->where('updated_at', '<', now()->subMinutes(30))
                ->count();

            if ($stuck > 0) {
                $stuckTwoHours = \Illuminate\Support\Facades\DB::table('processed_webhooks')
                    ->where('status', 'processing')
                    ->where('updated_at', '<', now()->subHours(2))
                    ->count();
                $isCritical = $stuckTwoHours > 0 || $stuck > 20;

                $this->alert(
                    $isCritical ? 'Webhooks stuck in processing' : 'Webhook processing may be stalled',
                    "{$stuck} webhook row(s) have sat in 'processing' for over 30 minutes — a handler likely crashed before finalize. 2Checkout retries can re-claim these, but a pile this old means something is systematically broken. Check the logs and Master Control → Billing Review.",
                    $isCritical ? 'critical' : 'warning',
                    $isCritical ? 'webhook_ledger_stuck_critical' : 'webhook_ledger_stuck_warning'
                );
            }
        } catch (\Throwable $e) {
            // DB error / table absent — skip. /health covers DB-level outages.
        }
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
        $logPath = storage_path('logs/scheduler.log');

        if (! file_exists($logPath)) {
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

    public function checkQueueWorkerHealth(): void
    {
        try {
            $oldestJob = \Illuminate\Support\Facades\DB::table('jobs')
                ->orderBy('id')
                ->first();

            if ($oldestJob === null) {
                // No jobs in the queue — worker is healthy (or no traffic).
                return;
            }

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
        }
    }

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

    private function checkSingleBackupDisk(string $diskName): void
    {
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk($diskName);

            $backupName = config('backup.backup.name', config('APP_NAME', 'Laravel') . ' Backup');
            $backupPath = $backupName;

            // List all files in the backup directory.
            $files = $disk->files($backupPath);

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
        }
    }
}