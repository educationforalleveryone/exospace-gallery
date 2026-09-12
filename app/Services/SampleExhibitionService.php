<?php

namespace App\Services;

use App\Models\VenueTemplate;

class SampleExhibitionService
{
    public function forVenue(VenueTemplate $venue): array
    {
        $config    = config('sample_exhibitions');
        $artworks  = $config['collection']['artworks'] ?? [];
        $selection = $config['venues'][$venue->slug]['selection'] ?? null;

        if (!is_array($selection) || $selection === []) {
            $selection = array_slice(array_keys($artworks), 0, 6);
        }

        $images = [];

        foreach ($selection as $index => $key) {
            $art = $artworks[$key] ?? null;
            if (!is_array($art)) {
                continue; // stale key in a curated selection — skip, never fatal
            }

            $width  = (int) ($art['width'] ?? 1600);
            $height = (int) ($art['height'] ?? 1600);
            $file   = $art['file'] ?? ($key . '.jpg');

            $url = asset('assets/sample/artworks/' . $file);

            $images[] = array_filter([
                'id'          => 'sample-' . ($index + 1),
                'url'         => $url,
                'textures'    => [
                    'thumb'   => $url,
                    'small'   => $url,
                    'medium'  => $url,
                    'large'   => $url,
                ],
                'width'       => $width,
                'height'      => $height,
                'aspectRatio' => $width / max($height, 1),
                'orientation' => $art['orientation'] ?? 'square',
                'title'       => $art['title'] ?? 'Untitled (sample)',
                'description' => $art['description'] ?? null,
                'medium'      => $art['medium'] ?? null,
                'year'        => $art['year'] ?? null,
                'dimensions'  => $art['dimensions'] ?? null,

                // HONESTY: samples are never for sale and carry no price.
                'price'       => null,
                'forSale'     => false,

                'externalUrl' => null,
            ], fn ($v) => $v !== null);
        }

        return $images;
    }

    public function noteFor(VenueTemplate $venue): ?string
    {
        return config('sample_exhibitions.venues.' . $venue->slug . '.note');
    }

    public function credit(): string
    {
        return config('sample_exhibitions.collection.credit', 'Sample exhibition — demonstration artworks');
    }
}
