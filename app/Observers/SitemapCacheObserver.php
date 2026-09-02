<?php

declare(strict_types=1);

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
 * GalleryImage, GalleryScheduleEvent (ITERATION 5 — event writes now
 * bump the version so the sitemap events group stays fresh; before this,
 * a newly announced opening never invalidated the sitemap cache) and
 * SeoPage.
 *
 * ITERATION-1 FIX (over-invalidation): the previous isRelevant() checked
 * `$model->wasRecentlyCreated` inside saved(). That flag stays TRUE on the
 * model instance until it is re-fetched — so every LATER save of a
 * newly-created model (e.g. the very next `$gallery->update(['view_count'])`)
 * was treated as another "creation" and bumped the version. Combined with
 * soft-deletes firing BOTH saved() (deleted_at is watched) AND deleted(),
 * a single gallery delete bumped the version twice. Net effect: the sitemap
 * cache churned on almost every write, defeating its purpose.
 *
 * The corrected event semantics:
 *   - created():  fires exactly once per INSERT            → bump (new URL).
 *   - saved():    fires for INSERT and UPDATE; for the insert it arrives
 *                 right after created() and is skipped via a one-shot
 *                 per-instance marker. For updates, wasChanged($watched)
 *                 alone decides — no wasRecentlyCreated involvement.
 *   - deleted():  soft deletes already bump via saved() (deleted_at is
 *                 watched), so this handler only bumps for models WITHOUT
 *                 SoftDeletes (hard deletes).
 *   - restored(): same logic — the restore save() already bumps via
 *                 saved() (deleted_at → null is watched).
 *   - forceDeleted(): runs a bare DELETE query — no save events → always bump.
 */
class SitemapCacheObserver
{
    /**
     * One-shot markers: object ids whose most recent event sequence was a
     * created() dispatch. The saved() event that immediately follows the
     * insert consumes (and clears) its marker so the creation bump is not
     * double-counted.
     *
     * @var array<int, true>
     */
    private array $justCreated = [];

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
        // ITERATION 5: events group inputs. starts_at/ends_at decide
        // upcoming↔past (inclusion flips), is_active decides inclusion,
        // title/description/type are the page content the crawler last
        // saw. No deleted_at — this model has no SoftDeletes; the deleted()
        // handler covers hard deletes.
        \App\Models\GalleryScheduleEvent::class => [
            'gallery_id', 'title', 'description', 'type',
            'starts_at', 'ends_at', 'is_active',
        ],
        // ITERATION 7 "Frontier": the sitemap's venues group. slug changes
        // the URL; name/description are the page content the crawler last
        // saw; is_active/is_draft/published_at/archived_at flip inclusion
        // (the active+published gate). view_count is deliberately NOT
        // watched — it moves on every gallery view and is not page content.
        \App\Models\VenueTemplate::class => [
            'slug', 'name', 'description', 'is_active', 'is_draft',
            'published_at', 'archived_at',
        ],
    ];

    public function created(Model $model): void
    {
        // A new row is (potentially) a new URL — always bump.
        $this->justCreated[spl_object_id($model)] = true;
        $this->bump();
    }

    public function saved(Model $model): void
    {
        $oid = spl_object_id($model);

        // The insert's own saved() dispatch: created() already bumped.
        if (isset($this->justCreated[$oid])) {
            unset($this->justCreated[$oid]);
            return;
        }

        if ($this->isRelevant($model)) {
            $this->bump();
        }
    }

    public function deleted(Model $model): void
    {
        // Both soft and hard deletes change the URL set. Soft deletes bypass
        // Model::save() entirely (SoftDeletes::runSoftDelete issues the
        // UPDATE directly), so NO saved() event fires — this hook is the
        // only bump for them. Hard deletes reach it too.
        $this->bump();
    }

    public function restored(Model $model): void
    {
        // restore() performs a save() first (deleted_at → null is watched),
        // which already bumped via saved(). Nothing to do here.
        if ($this->usesSoftDeletes($model)) {
            return;
        }

        $this->bump();
    }

    public function forceDeleted(Model $model): void
    {
        // forceDelete() issues a bare DELETE — no save events fire.
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

        // ITERATION-1 FIX: no wasRecentlyCreated check here — that flag
        // persists on the instance after creation and misclassified every
        // subsequent save as a creation. Creation is handled by created().
        return $model->wasChanged($watched);
    }

    private function usesSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    private function bump(): void
    {
        try {
            // ITERATION 4 FIX (lost updates): the old read-modify-write
            // (get → put) silently dropped concurrent increments — a bulk
            // upload firing N observer bumps in parallel requests could end
            // with a single net bump, or with two writers clobbering each
            // other back to the SAME version (a no-op invalidation).
            //
            // Atomic replacement: seed-then-increment. Cache::add() is
            // SETNX semantics (only writes when absent — this preserves the
            // getter's default of 1 for a missing key, the reason increment
            // alone was avoided), then Cache::increment() is an atomic
            // read-modify-write on every backend.
            \Illuminate\Support\Facades\Cache::add('seo:sitemap:version', 1);
            \Illuminate\Support\Facades\Cache::increment('seo:sitemap:version');
        } catch (\Throwable) {
            // Cache unavailable — sitemaps fall back to TTL-only staleness.
        }
    }
}
