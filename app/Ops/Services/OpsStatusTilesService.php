<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Models\ProcessedWebhook;
use Throwable;

/**
 * OpsCenter — OpsStatusTilesService (Iteration 4).
 *
 * The read-only fact layer behind the overview's Backup and Webhook tiles
 * AND the health score's data-protection component. One source of truth:
 * the tile, the score and (in the case of backups) OpsHealthService all
 * describe the same disks with the same thresholds, so the dashboard can
 * never disagree with itself.
 *
 * Backup freshness mirrors the existing monitors (OperationalAlertService
 * / OpsHealthService): a disk is STALE when its newest zip is older than
 * 26 hours, MISSING when no zip exists at all. Those two states are the
 * reasons backups exist — anything else is green.
 *
 * Webhook status summarizes the 2Checkout IPN ledger (processed_webhooks):
 * a lingering 'failed' row is an unprocessed billing event — a real,
 * answerable problem (the replay action exists precisely for it).
 *
 * Both methods NEVER throw and never write anything: unreadable disks or
 * missing tables degrade to an honest 'unknown' with a reason, exactly
 * like every other OpsCenter reader.
 */
class OpsStatusTilesService
{
    /**
     * Backup freshness per configured destination disk.
     *
     * @return array{
     *     disks: array<int, array{disk: string, status: string, file_count: int, newest_name: string|null, newest_age_hours: float|null, newest_size: int|null}>,
     *     status: string,   // healthy|degraded|critical|unknown
     *     reasons: string[]
     * }
     */
    public function backupStatus(): array
    {
        $disks = config('backup.backup.destination.disks', ['local']);
        $disks = is_array($disks) ? $disks : [$disks];
        $folder = (string) config('backup.backup.name', 'Laravel-backup');

        $rows = [];
        $reasons = [];
        $status = 'healthy';

        foreach ($disks as $diskName) {
            try {
                $disk = \Illuminate\Support\Facades\Storage::disk($diskName);
                $files = array_filter(
                    $disk->files($folder),
                    fn ($f) => str_ends_with($f, '.zip'),
                );

                if ($files === []) {
                    $rows[] = [
                        'disk' => (string) $diskName,
                        'status' => 'missing',
                        'file_count' => 0,
                        'newest_name' => null,
                        'newest_age_hours' => null,
                        'newest_size' => null,
                    ];
                    $reasons[] = "{$diskName}: no backup archives found";
                    $status = 'critical';

                    continue;
                }

                $newestFile = null;
                $newestTime = 0;
                foreach ($files as $file) {
                    $mtime = (int) $disk->lastModified($file);
                    if ($mtime > $newestTime) {
                        $newestTime = $mtime;
                        $newestFile = $file;
                    }
                }

                $ageHours = (time() - $newestTime) / 3600;
                $stale = $ageHours > 26.0;
                $size = null;
                try {
                    $size = (int) $disk->size($newestFile);
                } catch (Throwable) {
                    // Size is presentation sugar — never fail a disk read on it.
                }

                $rows[] = [
                    'disk' => (string) $diskName,
                    'status' => $stale ? 'stale' : 'ok',
                    'file_count' => count($files),
                    'newest_name' => basename((string) $newestFile),
                    'newest_age_hours' => round($ageHours, 1),
                    'newest_size' => $size,
                ];

                if ($stale) {
                    $reasons[] = sprintf('%s: newest backup is %.1f hours old (threshold: 26)', $diskName, $ageHours);
                    $status = 'critical';
                }
            } catch (Throwable) {
                // Unreadable disk: honest unknown for the row, degraded for
                // the rollup — we cannot claim the backups are fine.
                $rows[] = [
                    'disk' => (string) $diskName,
                    'status' => 'unreadable',
                    'file_count' => 0,
                    'newest_name' => null,
                    'newest_age_hours' => null,
                    'newest_size' => null,
                ];
                $reasons[] = "{$diskName}: disk could not be read";
                $status = ($status === 'critical') ? $status : 'degraded';
            }
        }

        if ($disks === []) {
            $reasons[] = 'No backup disks configured (BACKUP_DISKS)';
            $status = 'unknown';
        }

        if ($reasons === []) {
            $fresh = array_filter($rows, fn ($r) => $r['status'] === 'ok');
            $reasons[] = sprintf(
                '%d disk(s) with fresh backups (newest within 26 h)',
                count($fresh),
            );
        }

        return ['disks' => $rows, 'status' => $status, 'reasons' => $reasons];
    }

    /**
     * 2Checkout IPN ledger health (the replay targets).
     *
     * @return array{
     *     failed_count: int,
     *     oldest_failed_age_hours: float|null,
     *     failed_24h: int,
     *     processed_24h: int,
     *     status: string,   // healthy|degraded|critical|unknown
     *     reasons: string[]
     * }
     */
    public function webhookStatus(): array
    {
        $unknown = [
            'failed_count' => 0,
            'oldest_failed_age_hours' => null,
            'failed_24h' => 0,
            'processed_24h' => 0,
            'status' => 'unknown',
            'reasons' => ['Webhook ledger unavailable (processed_webhooks table not readable)'],
        ];

        try {
            $failedCount = (int) ProcessedWebhook::query()->where('status', 'failed')->count();
            $oldestFailed = ProcessedWebhook::query()
                ->where('status', 'failed')
                ->orderBy('updated_at')
                ->first();
            $failed24h = (int) ProcessedWebhook::query()
                ->where('status', 'failed')
                ->where('updated_at', '>=', now()->subDay())
                ->count();
            $processed24h = (int) ProcessedWebhook::query()
                ->where('status', 'processed')
                ->where('updated_at', '>=', now()->subDay())
                ->count();
        } catch (Throwable) {
            return $unknown;
        }

        $oldestAge = null;
        if ($oldestFailed !== null && $oldestFailed->updated_at !== null) {
            $oldestAge = round($oldestFailed->updated_at->diffInMinutes(now()) / 60, 1);
        }

        $status = 'healthy';
        $reasons = [];

        if ($failedCount > 5) {
            $status = 'critical';
            $reasons[] = "{$failedCount} failed webhook(s) in the ledger — billing events are not being processed";
        } elseif ($failedCount > 0) {
            $status = 'degraded';
            $reasons[] = "{$failedCount} failed webhook(s) awaiting replay";
        } else {
            $reasons[] = 'No failed webhooks — every billing event processed';
        }

        if ($processed24h > 0 && $failedCount === 0) {
            $reasons[] = "{$processed24h} webhook(s) processed in the last 24 h";
        }

        return [
            'failed_count' => $failedCount,
            'oldest_failed_age_hours' => $oldestAge,
            'failed_24h' => $failed24h,
            'processed_24h' => $processed24h,
            'status' => $status,
            'reasons' => $reasons,
        ];
    }
}
