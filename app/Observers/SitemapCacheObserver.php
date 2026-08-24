<?php

declare(strict_types=1);

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;

/**
 * Sitemap cache invalidation (SEO OS Iteration 4).
 *
 * All sitemap caches are keyed on a version number stored in the cache.
 * When an SEO-relevant attribute of a sitemap-listed entity changes, this
 * observer bumps the version — every versioned key "changes", and the
 * sitemaps regenerate lazily on their next request (no rebuild storms,
 * no TTL-only staleness).
 *
 * IMPORTANT: only SEO-RELEVANT changes bump the version. Denormalized
 * counters (view_count) and non-public fields must NOT invalidate —
 * otherwise every gallery view would flush the sitemap caches.
 *
 * Registered (AppServiceProvider::boot) for Gallery, Artist,
 * GalleryImage (+ SeoPage when Iteration 5 lands).
 */
class SitemapCacheObserver
{
    /**
     * Attributes that change a page's URL, content or indexability.
     *
     * @var array<class-string<Model>, array<int, string>>
     */
    private const WATCHED = [
        \App\Models\Gallery::class => [
            'slug', 'title', 'description', 'is_active', 'pin_hash',
            'opens_at', 'closes_at', 'venue_template_id', 'custom_domain',
            'custom_domain_verified_at', 'deleted_at',
        ],
        \App\Models\Artist::class => [
            'slug', 'name', 'bio', 'location', 'deleted_at',
        ],
        \App\Models\GalleryImage::class => [
            'gallery_id', 'artist_id', 'title', 'description',
            'medium', 'year', 'deleted_at', 'position_order',
        ],
    ];

    public function saved(Model $model): void
    {
        if ($this->isRelevant($model)) {
            $this->bump();
        }
    }

    public function deleted(Model $model): void
    {
        // Deletion always changes the URL set.
        $this->bump();
    }

    public function restored(Model $model): void
    {
        $this->bump();
    }

    public function forceDeleted(Model $model): void
    {
        $this->bump();
    }

    private function isRelevant(Model $model): bool
    {
        $watched = self::WATCHED[$model::class] ?? null;

        if ($watched === null) {
            // Unknown model type (e.g. SeoPage before its WATCHED entry
            // exists): conservatively bump on any save.
            return true;
        }

        // Creation of a row with SEO-meaningful defaults (slug/title).
        if (!$model->exists || $model->wasRecentlyCreated) {
            return true;
        }

        return $model->wasChanged($watched);
    }

    private function bump(): void
    {
        try {
            // read-modify-write instead of Cache::increment(): increment on a
            // MISSING key initializes to 1 on several backends, which would
            // collide with the getter's default of 1 (a no-op bump).
            // Concurrent bumps may lose one increment — acceptable: the TTL
            // still bounds sitemap staleness.
            $current = (int) \Illuminate\Support\Facades\Cache::get('seo:sitemap:version', 1);
            \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', $current + 1);
        } catch (\Throwable) {
            // Cache unavailable — sitemaps fall back to TTL-only staleness.
        }
    }
}
