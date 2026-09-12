<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class InternalLinkingService
{
    private const CACHE_TTL = 900; // 15 minutes

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
