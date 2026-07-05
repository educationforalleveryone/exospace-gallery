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
 *   - SESSION_DRIVER=redis: uses Redis SCAN + DEL
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
     * Laravel's Redis session driver stores sessions with keys like
     * `{prefix}:sessions:{sessionId}`. The `user_id` is stored inside
     * the session payload, not as a Redis key — so we can't directly
     * query by user_id.
     *
     * Instead, we SCAN all session keys, read each one, check if the
     * payload contains a banned user_id, and DELETE matching keys.
     * This is O(N) over all sessions — acceptable for small-to-medium
     * session counts (< 10k).
     *
     * P1-13 FIX: The del() call is now wired up, and the broad
     * `login_web_` heuristic (which matched ANY authenticated session)
     * has been removed. Only sessions containing a banned user_id
     * are deleted.
     */
    private function purgeRedis(array $bannedIds): void
    {
        $redis = \Illuminate\Support\Facades\Redis::connection('cache');
        $sessionPrefix = config('session.prefix', '') ?: 'sessions';
        $pattern = "{$sessionPrefix}:*";

        $deleted = 0;
        $iterator = null;

        do {
            [$iterator, $keys] = $redis->scan($iterator, ['match' => $pattern, 'count' => 100]);

            if (empty($keys)) {
                break;
            }

            foreach ($keys as $key) {
                $payload = $redis->get($key);
                if (! $payload) continue;

                // Check if the session payload contains a banned user_id.
                // Laravel serializes session data as a PHP serialized string.
                // We do a substring match for each banned user ID.
                $isBannedSession = false;
                foreach ($bannedIds as $id) {
                    // P1-13: Removed the broad "login_web_" heuristic that
                    // matched ANY authenticated session. Only match on
                    // the specific banned user_id patterns.
                    if (strpos($payload, "\"user_id\";i:{$id}") !== false ||
                        strpos($payload, "user_id|i:{$id}") !== false) {
                        $isBannedSession = true;
                        break;
                    }
                }

                if ($isBannedSession) {
                    // P1-13 FIX: Actually delete the key! Previously this
                    // line was missing — the code found the match but
                    // never called del(). $deleted stayed at 0 forever.
                    $redis->del($key);
                    $deleted++;
                }
            }
        } while ($iterator > 0);

        $this->info("Scanned Redis sessions for " . count($bannedIds) . " banned user(s). Deleted {$deleted} sessions.");

        Log::info('PurgeBannedUserSessions: purged Redis sessions', [
            'banned_users' => count($bannedIds),
            'sessions_deleted' => $deleted,
        ]);
    }
}
