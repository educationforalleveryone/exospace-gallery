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
     */
    public function checkAndAlert(): void
    {
        $this->checkFailedJobs();
        $this->checkDiskUsage();
        $this->checkSchedulerHealth();
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
}