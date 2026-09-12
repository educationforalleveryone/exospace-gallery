<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurgeBannedUserSessions extends Command
{
    protected $signature = 'exospace:purge-banned-sessions';
    protected $description = 'Delete all sessions for banned users.';

    public function handle(): int
    {
        $driver = config('session.driver');
        $bannedCount = User::whereNotNull('banned_at')->count();

        if ($bannedCount === 0) {
            $this->info('No banned users — nothing to purge.');
            return self::SUCCESS;
        }

        $bannedIds = User::whereNotNull('banned_at')->pluck('id')->toArray();

        switch ($driver) {
            case 'database':
                $this->purgeDatabase($bannedIds);
                break;

            case 'redis':
                $this->purgeRedis($bannedIds);
                break;

            case 'file':
                $this->info('SESSION_DRIVER=file — cannot query by user_id. CheckBanned middleware handles this on next request.');
                break;

            default:
                $this->info("SESSION_DRIVER={$driver} — no purge method available. CheckBanned middleware handles this on next request.");
                break;
        }

        return self::SUCCESS;
    }

    private function purgeDatabase(array $bannedIds): void
    {
        $deleted = DB::table('sessions')
            ->whereIn('user_id', $bannedIds)
            ->delete();

        $this->info("Purged {$deleted} sessions for " . count($bannedIds) . " banned user(s) from database.");

        Log::info('PurgeBannedUserSessions: purged database sessions', [
            'banned_users' => count($bannedIds),
            'sessions_deleted' => $deleted,
        ]);
    }

    private function purgeRedis(array $bannedIds): void
    {
        $storeName = config('session.connection')
            ?: (config('session.store') ?: config('session.driver'));
        $cache = \Illuminate\Support\Facades\Cache::store($storeName);

        $prefix = (string) config('cache.prefix', '');
        $pattern = $prefix.'*';
        $prefixLength = strlen($prefix);

        $connectionName = config("cache.stores.{$storeName}.connection")
            ?: (config('session.connection') ?: 'cache');

        $deleted = 0;
        $scanned = 0;
        $iterator = null;

        try {
            $redis = \Illuminate\Support\Facades\Redis::connection($connectionName);

            do {
                [$iterator, $keys] = $redis->scan($iterator, ['match' => $pattern, 'count' => 100]);

                if (empty($keys)) {
                    break;
                }

                foreach ($keys as $key) {
                    $sessionId = substr($key, $prefixLength);

                    if (! is_string($sessionId) || ! preg_match('/^[A-Za-z0-9]{40}$/', $sessionId)) {
                        continue;
                    }

                    $scanned++;

                    if ($this->sessionBelongsToBannedUser($cache, $sessionId, $bannedIds)) {
                        $cache->forget($sessionId);
                        $deleted++;
                    }
                }
            } while ($iterator > 0);
        } catch (\Throwable $e) {
            Log::warning('PurgeBannedUserSessions: Redis scan failed', [
                'error' => $e->getMessage(),
            ]);
            $this->error('Redis scan failed: '.$e->getMessage());

            return;
        }

        $this->info("Scanned {$scanned} session(s) in Redis for ".count($bannedIds).' banned user(s). Deleted '.$deleted.' session(s).');

        Log::info('PurgeBannedUserSessions: purged Redis sessions', [
            'banned_users' => count($bannedIds),
            'sessions_scanned' => $scanned,
            'sessions_deleted' => $deleted,
        ]);
    }

    private function sessionBelongsToBannedUser($cache, string $sessionId, array $bannedIds): bool
    {
        $payload = $cache->get($sessionId);

        if (! is_string($payload) || $payload === '') {
            return false;
        }

        if (config('session.encrypt')) {
            try {
                $payload = app('encrypter')->decrypt($payload);
            } catch (\Throwable) {
                // Undecryptable garbage — never treat it as a match.
                return false;
            }
        }

        $data = @unserialize($payload, ['allowed_classes' => false]);

        if (! is_array($data)) {
            return false;
        }

        $authKey = \Illuminate\Support\Facades\Auth::guard('web')->getName();

        foreach ($bannedIds as $id) {
            if (isset($data[$authKey]) && (string) $data[$authKey] === (string) $id) {
                return true;
            }
        }

        return false;
    }
}
