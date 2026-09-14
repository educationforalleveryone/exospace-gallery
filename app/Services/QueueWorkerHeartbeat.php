<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Liveness signal for the background queue worker.
 *
 * The worker stamps this on every loop iteration (AppServiceProvider,
 * Queue::looping). When the stamp goes stale, no worker is consuming the
 * queue and every queued job — including queued email — is stalled.
 */
final class QueueWorkerHeartbeat
{
    public const CACHE_KEY = 'ops:queue-worker:heartbeat';
    private const TTL_SECONDS = 1800;

    public static function stamp(): void
    {
        try {
            Cache::put(self::CACHE_KEY, now()->timestamp, self::TTL_SECONDS);
        } catch (\Throwable) {
            // Cache trouble must never take the worker loop down.
        }
    }

    public static function ageSeconds(): ?int
    {
        try {
            $stamp = Cache::get(self::CACHE_KEY);
        } catch (\Throwable) {
            return null;
        }

        return $stamp === null ? null : max(0, time() - (int) $stamp);
    }
}
