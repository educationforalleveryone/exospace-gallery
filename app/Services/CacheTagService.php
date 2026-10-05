<?php

namespace App\Services;

use App\Support\ResilientCache;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CacheTagService
{
    public function supportsTags(): bool
    {
        $store = Cache::getStore();

        return $store instanceof \Illuminate\Cache\RedisStore
            || $store instanceof \Illuminate\Cache\MemcachedStore
            || $store instanceof \Illuminate\Cache\ApcStore;
    }

    public function rememberTagged(array $tags, string $key, \DateTimeInterface $ttl, Closure $callback): mixed
    {
        if ($this->supportsTags()) {
            try {
                return Cache::tags($tags)->remember($key, $ttl, $callback);
            } catch (Throwable $e) {
                $this->report('rememberTagged', $key, $e);

                return $callback();
            }
        }

        // Fallback: track the key under each tag, then plain Cache::remember.
        $this->trackKeyInTags($tags, $key);

        return ResilientCache::remember($key, $ttl, $callback);
    }

    public function flexibleTagged(array $tags, string $key, array $ttls, Closure $callback): mixed
    {
        if ($this->supportsTags()) {
            try {
                return Cache::tags($tags)->flexible($key, $ttls, $callback);
            } catch (Throwable $e) {
                $this->report('flexibleTagged', $key, $e);

                return $callback();
            }
        }

        $this->trackKeyInTags($tags, $key);

        return ResilientCache::flexible($key, $ttls, $callback);
    }

    public function invalidateTag(string $tag): void
    {
        if ($this->supportsTags()) {
            try {
                Cache::tags([$tag])->flush();

                return;
            } catch (Throwable $e) {
                // The flush could not run — cached entries live on until their
                // TTL expires. Logged so the staleness stays observable.
                $this->report('invalidateTag', $tag, $e);

                return;
            }
        }

        $indexKey = "tag_index:{$tag}";
        $keys = ResilientCache::get($indexKey, []);

        if (is_array($keys)) {
            foreach ($keys as $k) {
                try {
                    Cache::forget($k);
                } catch (Throwable $e) {
                    $this->report('invalidateTag', $k, $e);
                }
            }
        }

        try {
            Cache::forget($indexKey);
        } catch (Throwable $e) {
            $this->report('invalidateTag', $indexKey, $e);
        }
    }

    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->invalidateTag($tag);
        }
    }

    private function trackKeyInTags(array $tags, string $key): void
    {
        foreach ($tags as $tag) {
            $indexKey = "tag_index:{$tag}";
            $keys = ResilientCache::get($indexKey, []);

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

            try {
                Cache::put($indexKey, $keys, now()->addDays(30));
            } catch (Throwable $e) {
                // Tracking is best-effort: without it only the targeted
                // forget path degrades, the cached value itself still serves.
                $this->report('trackKeyInTags', $indexKey, $e);
            }
        }
    }

    private function report(string $op, string $key, Throwable $e): void
    {
        Log::warning('Cache unavailable — serving uncached data', [
            'op' => $op,
            'key' => $key,
            'error' => $e->getMessage(),
        ]);
    }
}
