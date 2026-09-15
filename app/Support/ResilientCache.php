<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cache reads that must not take the request down when the cache store is
 * unavailable. On cache failure the callback runs uncached and a warning is
 * logged, so the failure stays observable while the database keeps the
 * application serving.
 */
class ResilientCache
{
    public static function remember(string $key, $ttl, Closure $callback): mixed
    {
        try {
            return Cache::remember($key, $ttl, $callback);
        } catch (Throwable $e) {
            self::report('remember', $key, $e);

            return $callback();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = Cache::get($key, $default);
        } catch (Throwable $e) {
            self::report('get', $key, $e);

            return $default;
        }

        // A cache hit never returns the default it was given, so a miss
        // means the entry genuinely does not exist.
        return $value;
    }

    /**
     * @param array{0: \DateTimeInterface|int, 1: \DateTimeInterface|int} $ttls [fresh, stale]
     * @param array{seconds?: int}|null $lockSeconds
     */
    public static function flexible(string $key, array $ttls, Closure $callback, ?array $lockSeconds = null): mixed
    {
        try {
            return Cache::flexible($key, $ttls, $callback, $lockSeconds);
        } catch (Throwable $e) {
            self::report('flexible', $key, $e);

            return $callback();
        }
    }

    private static function report(string $op, string $key, Throwable $e): void
    {
        Log::warning('Cache unavailable — serving uncached data', [
            'op'     => $op,
            'key'    => $key,
            'error'  => $e->getMessage(),
        ]);
    }
}
