<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * P3-16 FIX: Tagged-cache helper with graceful fallback.
 *
 * Laravel's Cache::tags(['foo', 'bar']) only works on Redis, Memcached, and
 * APC stores — NOT on the database, file, or array stores. Exospace defaults
 * to CACHE_STORE=redis in production (per .env.example) but uses
 * CACHE_STORE=database / array in dev/CI. So we can't just unconditionally
 * switch all callers to Cache::tags().
 *
 * This service provides a single API that:
 *   - Uses Cache::tags() when the active store supports it (Redis/Memcached/APC)
 *   - Falls back to per-key Cache::forget() when the store doesn't support tags
 *
 * The fallback tracks keys in a "tag index" cache entry, so when
 * invalidateTag() is called, it looks up the tracked keys and forgets them
 * individually. This is less efficient than Redis's native tag implementation
 * (which uses a set intersection) but produces the same observable behavior.
 *
 * Usage:
 *
 *   $service = app(CacheTagService::class);
 *   $service->rememberTagged(['analytics', "gallery:{$galleryId}"], $key, $ttl, function () {
 *       return compute_expensive_thing();
 *   });
 *   // later, when the gallery's data changes:
 *   $service->invalidateTag("gallery:{$galleryId}");
 *
 * Common tags used by Exospace:
 *   - 'analytics' — all analytics caches (invalidated by RollupAnalytics)
 *   - 'analytics:gallery:{id}' — per-gallery analytics (invalidated on new event)
 *   - 'gallery:{id}' — gallery object caches (invalidated on gallery update)
 *   - 'sitemap' — sitemap/feed caches (invalidated on gallery create/destroy)
 *   - 'og' — OG image caches (invalidated on gallery title/description change)
 */
class CacheTagService
{
    /**
     * Check if the current cache store supports native tag operations.
     * Stores that DO support tags: redis, memcached, apc.
     * Stores that DON'T: database, file, array, dynamodb.
     */
    public function supportsTags(): bool
    {
        $store = Cache::getStore();

        return $store instanceof \Illuminate\Cache\RedisStore
            || $store instanceof \Illuminate\Cache\MemcachedStore
            || $store instanceof \Illuminate\Cache\ApcStore;
    }

    /**
     * Remember a value under the given tag(s) with TTL.
     *
     * @param  array<int,string>  $tags     Tags to attach (Redis native) or
     *                                       track (fallback).
     * @param  string             $key      Cache key (must be globally unique).
     * @param  \DateTimeInterface  $ttl     Time-to-live.
     * @param  Closure            $callback Computes the value if not cached.
     */
    public function rememberTagged(array $tags, string $key, \DateTimeInterface $ttl, Closure $callback): mixed
    {
        if ($this->supportsTags()) {
            return Cache::tags($tags)->remember($key, $ttl, $callback);
        }

        // Fallback: track the key under each tag, then plain Cache::remember.
        $this->trackKeyInTags($tags, $key);

        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Flexible-remember a value under the given tag(s).
     *
     * Flexible caching serves stale while revalidating: returns the stale
     * value immediately if present, then async-recomputes if it's past
     * the primary TTL but within the secondary TTL.
     */
    public function flexibleTagged(array $tags, string $key, array $ttls, Closure $callback): mixed
    {
        if ($this->supportsTags()) {
            return Cache::tags($tags)->flexible($key, $ttls, $callback);
        }

        $this->trackKeyInTags($tags, $key);

        return Cache::flexible($key, $ttls, $callback);
    }

    /**
     * Invalidate (flush) all entries under a single tag.
     *
     * With Redis/Memcached/APC, this is O(1) — a single Redis DEL on the
     * tag's set. With the fallback, this iterates the tracked keys and
     * forgets them individually (O(N) where N is the number of tracked
     * keys, but each forget is O(1)).
     */
    public function invalidateTag(string $tag): void
    {
        if ($this->supportsTags()) {
            Cache::tags([$tag])->flush();
            return;
        }

        // Fallback: read the tag index, forget each tracked key, then clear
        // the tag index itself.
        $indexKey = "tag_index:{$tag}";
        $keys = Cache::get($indexKey, []);

        if (is_array($keys)) {
            foreach ($keys as $k) {
                Cache::forget($k);
            }
        }

        Cache::forget($indexKey);
    }

    /**
     * Invalidate multiple tags at once.
     */
    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->invalidateTag($tag);
        }
    }

    /**
     * Track a cache key in each tag's index (fallback mode only).
     *
     * The index is stored as an array of keys under `tag_index:{tag}`. We
     * cap the array at 1000 entries per tag to prevent unbounded growth —
     * if a tag has more than 1000 keys, the oldest entries are evicted
     * (LRU-style), and invalidateTag() will miss them. This is acceptable
     * because the fallback is for dev/CI only; production uses Redis native
     * tags which have no such limit.
     */
    private function trackKeyInTags(array $tags, string $key): void
    {
        foreach ($tags as $tag) {
            $indexKey = "tag_index:{$tag}";
            $keys = Cache::get($indexKey, []);

            if (! is_array($keys)) {
                $keys = [];
            }

            // Avoid duplicates
            if (! in_array($key, $keys, true)) {
                $keys[] = $key;
            }

            // Cap at 1000 entries
            if (count($keys) > 1000) {
                $keys = array_slice($keys, -1000);
            }

            // Store with a long TTL (30 days) so the index outlives any
            // individual cache entry. Stale index entries are harmless —
            // forgetting a key that doesn't exist is a no-op.
            Cache::put($indexKey, $keys, now()->addDays(30));
        }
    }
}
