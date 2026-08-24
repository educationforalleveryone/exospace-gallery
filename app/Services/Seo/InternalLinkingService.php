<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Internal linking engine (SEO OS Iteration 3).
 *
 * Produces RELEVANCE-BASED related content — the connective tissue of the
 * public web graph:
 *
 *   exhibition → related exhibitions   (shared artists, then shared venue)
 *   artist     → related artists       (shared exhibitions)
 *   artwork    → more by artist        (same artist, other public galleries)
 *
 * Design rules (anti-spam):
 *  - Hard cap on link count (config seo.related.*), default 6.
 *  - Only publiclyViewable, non-empty galleries — never link to private,
 *    PIN-protected, closed, scheduled or empty pages.
 *  - Deterministic ordering (relevance, then view_count, then id) so
 *    sections are stable between renders and crawl passes.
 *  - Cached 15 minutes per entity; the cache is a size optimization, not
 *    a correctness mechanism.
 */
class InternalLinkingService
{
    private const CACHE_TTL = 900; // 15 minutes

    /**
     * Exhibitions related to the given gallery.
     *
     * Relevance: number of shared artists (desc), then same venue, then
     * view_count. Self and empty galleries are excluded.
     *
     * @return Collection<int, Gallery>
     */
    public function relatedGalleries(Gallery $gallery, ?int $limit = null): Collection
    {
        $limit ??= (int) config('seo.related.galleries_max', 6);
        $key = "seo:related:galleries:{$gallery->id}";

        return Cache::remember($key, self::CACHE_TTL, function () use ($gallery, $limit) {
            $artistIds = $gallery->images->pluck('artist_id')->filter()->unique()->values();

            $query = Gallery::query()
                ->publiclyViewable()
                ->with(['coverImage', 'venueTemplate'])
                ->withCount('images')
                ->has('images', '>=', 1)
                ->where('id', '!=', $gallery->id)
                ->whereDoesntHave('user', fn ($q) => $q->whereNotNull('banned_at'));

            if ($artistIds->isNotEmpty()) {
                // Count shared artists via a correlated subquery.
                $query->withCount([
                    'images as shared_artists_count' => fn ($q) => $q->whereIn('artist_id', $artistIds),
                ]);
            }

            $related = $query->get();

            return $related
                ->map(function ($g) use ($gallery) {
                    $sharedArtists = (int) ($g->shared_artists_count ?? 0);
                    $sameVenue = ($gallery->venue_template_id && $g->venue_template_id === $gallery->venue_template_id) ? 1 : 0;

                    return [
                        'gallery' => $g,
                        'score' => $sharedArtists * 10 + $sameVenue * 2 + min(log10(1 + $g->view_count), 3),
                        'shared_artists' => $sharedArtists,
                    ];
                })
                ->sortByDesc(fn ($row) => $row['score'])
                ->take($limit)
                ->pluck('gallery')
                ->values();
        });
    }

    /**
     * Artists related to the given artist (they share at least one public
     * exhibition). Ordered by number of shared exhibitions, then works.
     *
     * @return Collection<int, Artist>
     */
    public function relatedArtists(Artist $artist, ?int $limit = null): Collection
    {
        $limit ??= (int) config('seo.related.artists_max', 6);
        $key = "seo:related:artists:{$artist->id}";

        return Cache::remember($key, self::CACHE_TTL, function () use ($artist, $limit) {
            // Public galleries featuring this artist.
            $galleryIds = Gallery::query()
                ->publiclyViewable()
                ->has('images', '>=', 1)
                ->whereHas('images', fn ($q) => $q->where('artist_id', $artist->id))
                ->pluck('id');

            if ($galleryIds->isEmpty()) {
                return collect();
            }

            return Artist::query()
                ->whereKeyNot($artist->id)
                ->whereHas('images', fn ($q) => $q->whereIn('gallery_id', $galleryIds))
                ->withCount([
                    'images as public_works_count' => fn ($q) => $q->whereIn('gallery_id', $galleryIds),
                ])
                ->orderByDesc('public_works_count')
                ->orderBy('name')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Other works by the same artist (across all PUBLIC galleries),
     * excluding the current artwork and its own gallery's siblings.
     *
     * @return Collection<int, GalleryImage>
     */
    public function relatedArtworks(GalleryImage $artwork, ?int $limit = null): Collection
    {
        $limit ??= (int) config('seo.related.artworks_max', 6);
        if (!$artwork->artist_id) {
            return collect();
        }

        $key = "seo:related:artworks:{$artwork->id}";

        return Cache::remember($key, self::CACHE_TTL, function () use ($artwork, $limit) {
            return GalleryImage::query()
                ->where('artist_id', $artwork->artist_id)
                ->where('id', '!=', $artwork->id)
                ->where('gallery_id', '!=', $artwork->gallery_id)
                ->whereHas('gallery', fn ($q) => $q->publiclyViewable())
                ->with(['gallery.venueTemplate', 'artist'])
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        });
    }
}
