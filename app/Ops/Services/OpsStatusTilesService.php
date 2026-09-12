<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Models\ProcessedWebhook;
use Throwable;

class OpsStatusTilesService
{
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
