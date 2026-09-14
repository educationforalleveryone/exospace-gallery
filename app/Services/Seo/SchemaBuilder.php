<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use Illuminate\Support\Str;

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

    public function webSite(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $this->siteName(),
            'url' => $this->appUrl(),
        ];
    }

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
            $artist->website_url,
            $artist->instagram_url,
            $artist->twitter_url,
        ]));
        if ($sameAs !== []) {
            $schema['sameAs'] = $sameAs;
        }

        return $schema;
    }

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

    public function visualArtwork(GalleryImage $image, ?Gallery $gallery = null, bool $minimal = false): array
    {
        $schema = [
            '@type' => 'VisualArtwork',
            'name' => $image->title ?: $image->original_name ?: 'Untitled',
        ];

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

    private function hubItemUrl($item): string
    {
        if ($item instanceof Gallery) {
            return $item->public_url;
        }

        return url('/artist/' . $item->slug);
    }

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
