<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use Illuminate\Support\Str;

/**
 * Central schema.org builder (SEO OS Iteration 3).
 *
 * ONE place that knows how to turn Exospace entities into structured data.
 * Rules enforced here:
 *
 *  1. Every property maps to a REAL column/relation — no fabricated
 *     reviews, ratings, statistics, prices, or dates, ever.
 *  2. Graphs are plain PHP arrays; encoding happens once in <x-seo>.
 *  3. Entity eligibility (e.g. "gallery actually has artworks") is decided
 *     by the CALLER (quality rules live there); the builder only maps data.
 *
 * Usage:
 *   $schema = app(SchemaBuilder::class);
 *   $graphs = [
 *       $schema->exhibitionEvent($gallery),
 *       $schema->artworkItemList($gallery),
 *   ];
 *   $seo = $seo->with(['jsonLd' => $graphs]);
 */
class SchemaBuilder
{
    private function siteName(): string
    {
        return (string) config('seo.site_name', 'Exospace');
    }

    private function appUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    // ── Site-level ───────────────────────────────────────────────────────

    /**
     * Organization schema for the platform itself. sameAs intentionally
     * reads from config so social profiles are added in ONE place.
     *
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $this->siteName(),
            'url' => $this->appUrl(),
            'logo' => $this->appUrl() . '/android-chrome-192x192.png',
            'description' => (string) config('seo.default_description'),
        ];

        $sameAs = array_values(array_filter(array_map(
            'trim',
            (array) config('seo.organization.same_as', []),
        )));
        if ($sameAs !== []) {
            $schema['sameAs'] = $sameAs;
        }

        return $schema;
    }

    /**
     * WebSite schema. SearchAction is deliberately OMITTED — the platform
     * has no site-wide search today; emitting a dead search URL would be
     * misleading structured data.
     *
     * @return array<string, mixed>
     */
    public function webSite(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $this->siteName(),
            'url' => $this->appUrl(),
        ];
    }

    // ── People ───────────────────────────────────────────────────────────

    /**
     * Person schema from real artist data.
     *
     * @return array<string, mixed>
     */
    public function person(Artist $artist, ?string $profileUrl = null): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $artist->name,
            'url' => $profileUrl ?: url('/artist/' . $artist->slug),
        ];

        if ($artist->bio) {
            $schema['description'] = Str::limit($artist->bio, 300);
        }
        if ($artist->portrait_url) {
            $schema['image'] = $artist->portrait_url;
        }
        if ($artist->location) {
            $schema['homeLocation'] = [
                '@type' => 'Place',
                'name' => $artist->location,
            ];
        }

        $sameAs = array_values(array_filter([
            $artist->website,
            $artist->instagram_url,
            $artist->twitter_url,
        ]));
        if ($sameAs !== []) {
            $schema['sameAs'] = $sameAs;
        }

        return $schema;
    }

    // ── Exhibitions ──────────────────────────────────────────────────────

    /**
     * ExhibitionEvent schema. Only meaningful when the gallery carries
     * schedule data or content — the caller decides. Galleries without any
     * dates are better represented as CollectionPage (see collectionPage()).
     *
     * @return array<string, mixed>
     */
    public function exhibitionEvent(Gallery $gallery): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'ExhibitionEvent',
            'name' => $gallery->title ?: 'Untitled Exhibition',
            'url' => $gallery->public_url,
            'image' => url("/gallery/{$gallery->slug}/og-image"),
            'location' => [
                '@type' => 'VirtualLocation',
                'url' => $gallery->public_url,
            ],
            'organizer' => [
                '@type' => 'Organization',
                'name' => $this->siteName(),
                'url' => $this->appUrl(),
            ],
            'eventStatus' => $this->exhibitionStatus($gallery),
        ];

        if ($gallery->description) {
            $schema['description'] = Str::limit($gallery->description, 300);
        }
        if ($gallery->opens_at) {
            $schema['startDate'] = $gallery->opens_at->toIso8601String();
        }
        if ($gallery->closes_at) {
            $schema['endDate'] = $gallery->closes_at->toIso8601String();
        }

        return $schema;
    }

    /**
     * CollectionPage representation of a gallery — used when the gallery
     * has no schedule dates (a permanent collection, not a dated event).
     *
     * @return array<string, mixed>
     */
    public function collectionPage(Gallery $gallery): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $gallery->title ?: 'Untitled Exhibition',
            'url' => $gallery->public_url,
        ];

        if ($gallery->description) {
            $schema['description'] = Str::limit($gallery->description, 300);
        }

        return $schema;
    }

    /**
     * ItemList of the artworks in a gallery. Each ListItem carries the
     * artwork URL (the artwork landing page) plus a minimal VisualArtwork
     * item summary from real columns.
     *
     * @param  iterable<GalleryImage>  $images
     * @return array<string, mixed>
     */
    public function artworkItemList(Gallery $gallery, iterable $images, int $totalCount = 0): array
    {
        $items = [];
        $position = 0;
        foreach ($images as $image) {
            $position++;
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'url' => url("/gallery/{$gallery->slug}/artwork/{$image->id}"),
                'item' => $this->visualArtwork($image, $gallery, minimal: true),
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => ($gallery->title ?: 'Untitled Exhibition') . ' — Artworks',
            'url' => $gallery->public_url,
            'numberOfItems' => $totalCount > 0 ? $totalCount : $position,
            'itemListElement' => $items,
        ];
    }

    // ── Artworks ─────────────────────────────────────────────────────────

    /**
     * VisualArtwork schema from real columns.
     *
     * @return array<string, mixed>
     */
    public function visualArtwork(GalleryImage $image, ?Gallery $gallery = null, bool $minimal = false): array
    {
        $schema = [
            '@type' => 'VisualArtwork',
            'name' => $image->title ?: $image->original_name ?: 'Untitled',
        ];

        // Minimal mode (used inside ItemList): no description/context to
        // keep the JSON-LD payload small on large galleries.
        if (!$minimal) {
            $schema['@context'] = 'https://schema.org';
            if ($image->description) {
                $schema['description'] = Str::limit($image->description, 500);
            }
            $schema['isAccessibleForFree'] = true;
            $schema['image'] = asset($image->path);
        }

        if ($image->artist) {
            $schema['creator'] = [
                '@type' => 'Person',
                'name' => $image->artist->name,
                'url' => url('/artist/' . $image->artist->slug),
            ];
        }

        if ($image->medium) {
            $schema['artMedium'] = $image->medium;
        }
        if ($image->year) {
            $schema['dateCreated'] = (string) $image->year;
        }
        if ($image->dimensions) {
            // Physical dimensions string ("120 × 80 cm") — schema `size`.
            // (artworkSurface means the SUPPORT material and was previously
            // misused for dimensions — audit M6, fixed.)
            $schema['size'] = $image->dimensions;
        }

        if ($image->for_sale && $image->price) {
            $schema['offers'] = [
                '@type' => 'Offer',
                'price' => number_format((float) $image->price, 2, '.', ''),
                'priceCurrency' => $image->currency ?: 'USD',
                'availability' => 'https://schema.org/InStock',
            ];
        }

        if ($gallery && !$minimal) {
            $schema['isPartOf'] = [
                '@type' => 'CollectionPage',
                'name' => $gallery->title ?: 'Untitled Exhibition',
                'url' => $gallery->public_url,
            ];
        }

        return $schema;
    }

    // ── Hubs ─────────────────────────────────────────────────────────────

    /**
     * CollectionPage for a hub (discover, artists, venues).
     *
     * @param  iterable<Gallery|Artist>|null  $items
     * @return array<string, mixed>
     */
    public function hubCollectionPage(string $name, string $url, ?iterable $items = null): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'url' => $url,
        ];

        if ($items !== null) {
            $list = [];
            $position = 0;
            foreach ($items as $item) {
                $position++;
                if ($position > 25) {
                    break; // keep payload small — hubs paginate anyway
                }
                $list[] = [
                    '@type' => 'ListItem',
                    'position' => $position,
                    'url' => $this->hubItemUrl($item),
                    'name' => $item->title ?? $item->name ?? null,
                ];
            }
            if ($list !== []) {
                $schema['mainEntity'] = [
                    '@type' => 'ItemList',
                    'numberOfItems' => count($list),
                    'itemListElement' => $list,
                ];
            }
        }

        return $schema;
    }

    /**
     * @param  Gallery|Artist  $item
     */
    private function hubItemUrl($item): string
    {
        if ($item instanceof Gallery) {
            return $item->public_url;
        }

        return url('/artist/' . $item->slug);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function exhibitionStatus(Gallery $gallery): string
    {
        if ($gallery->hasNotOpenedYet()) {
            return 'https://schema.org/EventScheduled';
        }
        if ($gallery->hasClosed()) {
            return 'https://schema.org/EventPostponed';
        }

        return 'https://schema.org/EventInProgress';
    }
}
