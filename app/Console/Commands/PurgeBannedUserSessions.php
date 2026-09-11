<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Purge sessions for banned users. (Task H51 / audit H16)
 *
 * The CheckBanned middleware purges the CURRENT session when it detects
 * a banned user, but the user's OTHER sessions (laptop, mobile, other
 * browsers) remain valid until their next request hits the middleware.
 *
 * This command purges ALL sessions for banned users by deleting rows
 * from the `sessions` table where `user_id` matches a banned user.
 *
 * Works with:
 *   - SESSION_DRIVER=database: directly deletes from the sessions table
 *   - SESSION_DRIVER=redis: uses Redis SCAN + the session cache store
 *   - SESSION_DRIVER=file: no-op (file sessions can't be queried by user_id;
 *     the CheckBanned middleware handles it on next request)
 *
 * P1-13 FIX (audit): The Redis branch previously had a dead-code bug —
 * it scanned keys, found matches via substring, but never called
 * $redis->del($key) and never incremented $deleted. The $deleted counter
 * stayed at 0 forever. Additionally, the `login_web_` heuristic matched
 * ANY authenticated session, not just banned users. Both issues are fixed:
 *   1. The del() call is now wired up
 *   2. The login_web_ heuristic is removed — only user_id matches trigger deletion
 *
 * SESSION-ITERATION FIX (Iter-10): the Redis branch was still a silent
 * NO-OP under the production configuration (SESSION_DRIVER=redis,
 * SESSION_ENCRYPT=true). Four independent defects, each fatal on its own:
 *
 *   1. SCAN pattern: Laravel's cache-backed session handler stores each
 *      session under the RAW session id, prefixed by the cache store's
 *      prefix (see Illuminate\Session\CacheBasedSessionHandler — the cache
 *      key IS the session id; Illuminate\Cache\RedisStore prepends
 *      config('cache.prefix'), e.g. "exospace-cache-<40-char-id>"). The old
 *      pattern "sessions:*" therefore matched NOTHING.
 *   2. Encryption: with session encryption enabled (SEC-11 default), the
 *      stored payload is CIPHERTEXT — no plaintext substring can ever
 *      match. The payload must be decrypted with the app encrypter first.
 *   3. Match key: authenticated sessions don't carry a "user_id" session
 *      key at all — SessionGuard stores the user id under
 *      "login_web_".sha1(SessionGuard::class) (exactly what
 *      Auth::guard('web')->getName() returns, and what the database
 *      driver's user_id COLUMN is derived from). The old "user_id"
 *      patterns never matched a PHP-serialized payload either.
 *   4. Layer bypass: raw Redis GET/DEL bypassed the cache store's
 *      serialization layer entirely.
 *
 * The branch now reads and deletes through the SAME cache store the
 * session manager resolves (SessionManager::createCacheHandler uses
 * config('session.connection') ?? (config('session.store') ?: driver)),
 * scans with the cache prefix pattern, keeps only keys whose suffix is a
 * well-formed 40-char session id, decrypts when session encryption is on,
 * and matches the REAL guard session key. Deletes go through the cache
 * store (forget), so the serialization layer is never bypassed.
 *
 * Scheduled every 5 minutes via routes/console.php.
 */
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

    /**
     * Delete sessions from the database sessions table.
     */
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

    /**
     * Delete sessions from Redis.
     *
     * SESSION-ITERATION FIX (Iter-10): see the class docblock for the full
     * defect list this rewrite addresses. In short: enumerate candidate
     * keys with SCAN (the only Redis primitive for key enumeration), but
     * READ and DELETE through the exact cache store the session manager
     * uses, decrypting the payload when session encryption is enabled and
     * matching the real SessionGuard auth key instead of a "user_id" key
     * that does not exist in session payloads.
     */
    private function purgeRedis(array $bannedIds): void
    {
        // The cache store the session manager itself resolves for the redis
        // driver (SessionManager::createCacheHandler):
        //   connection ?? (session.store ?: session.driver)
        // In production this is the 'redis' cache store on the 'cache'
        // connection — the same place CacheBasedSessionHandler reads/writes.
        $storeName = config('session.connection')
            ?: (config('session.store') ?: config('session.driver'));
        $cache = \Illuminate\Support\Facades\Cache::store($storeName);

        $prefix = (string) config('cache.prefix', '');
        $pattern = $prefix.'*';
        $prefixLength = strlen($prefix);

        // The SCAN itself has no cache-store abstraction — enumerate through
        // the same Redis connection the session cache store uses.
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
                    // Keep only well-formed session keys: the cache prefix
                    // followed by a 40-char alphanumeric session id (the id
                    // shape Store::isValidId enforces). This keeps ordinary
                    // cache entries (analytics, galleries, rate limits)
                    // out of the read path entirely.
                    $sessionId = substr($key, $prefixLength);

                    if (! is_string($sessionId) || ! preg_match('/^[A-Za-z0-9]{40}$/', $sessionId)) {
                        continue;
                    }

                    $scanned++;

                    if ($this->sessionBelongsToBannedUser($cache, $sessionId, $bannedIds)) {
                        // Delete through the cache store so the same
                        // serialization layer that wrote the value also
                        // deletes it.
                        $cache->forget($sessionId);
                        $deleted++;
                    }
                }
            } while ($iterator > 0);
        } catch (\Throwable $e) {
            // Never fail the schedule because Redis is briefly unreachable —
            // the per-request CheckBanned middleware still enforces the ban.
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

    /**
     * Does the stored session payload authenticate one of the banned users?
     *
     * Reads through the session cache store (so the cache layer's own
     * serialization is reversed), decrypts when session encryption is on
     * (SEC-11 default), then checks the REAL SessionGuard authentication
     * key — "login_web_".sha1(guard class) — which is exactly the key the
     * database driver derives its user_id column from.
     */
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
