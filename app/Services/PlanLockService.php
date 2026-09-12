<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PlanLockService
{
    public const LOCK_TTL = 60;

    public const LOCK_WAIT = 5;

    public const LOCK_BUSY = '__PLAN_LOCK_BUSY__';

    public function withUserLock(int $userId, callable $callback): mixed
    {
        $lock = Cache::lock("plan_lock:user:{$userId}", self::LOCK_TTL);

        try {
            $acquired = $lock->block(self::LOCK_WAIT, function () use ($callback) {
                // Lock acquired — run the critical section.
                return $callback();
            });
            return $acquired === false ? self::LOCK_BUSY : $acquired;
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::info('PlanLockService: lock busy, concurrent upgrade/downgrade blocked', [
                'user_id' => $userId,
            ]);
            return self::LOCK_BUSY;
        }
    }

    public function isLocked(int $userId): bool
    {
        return Cache::has("plan_lock:user:{$userId}");
    }
}
