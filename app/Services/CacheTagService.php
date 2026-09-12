<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

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
            return Cache::tags($tags)->remember($key, $ttl, $callback);
        }

        // Fallback: track the key under each tag, then plain Cache::remember.
        $this->trackKeyInTags($tags, $key);

        return Cache::remember($key, $ttl, $callback);
    }

    public function flexibleTagged(array $tags, string $key, array $ttls, Closure $callback): mixed
    {
        if ($this->supportsTags()) {
            return Cache::tags($tags)->flexible($key, $ttls, $callback);
        }

        $this->trackKeyInTags($tags, $key);

        return Cache::flexible($key, $ttls, $callback);
    }

    public function invalidateTag(string $tag): void
    {
        if ($this->supportsTags()) {
            Cache::tags([$tag])->flush();
            return;
        }

        $indexKey = "tag_index:{$tag}";
        $keys = Cache::get($indexKey, []);

        if (is_array($keys)) {
            foreach ($keys as $k) {
                Cache::forget($k);
            }
        }

        Cache::forget($indexKey);
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

            Cache::put($indexKey, $keys, now()->addDays(30));
        }
    }
}
