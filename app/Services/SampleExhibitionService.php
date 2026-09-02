<?php

namespace App\Services;

use App\Models\VenueTemplate;

/**
 * Iteration 1 "The Rehearsal" (roadmap P1.1) — builds the sample exhibition
 * image payload for walkable venue previews.
 *
 * DESIGN CONTRACT
 * ---------------
 *   - SAMPLE-ONLY: the returned artworks never come from the galleries or
 *     images tables. No user data can leak into a preview, because none is
 *     ever queried (test: VenuePreviewIterationTest::test_sample_data_isolation).
 *   - DETERMINISTIC: the same venue always yields the same hang in the same
 *     order (config-driven selection), so previews are stable for QA,
 *     screenshots and marketing stills.
 *   - HONEST: for_sale is false, no artist profile is referenced (the
 *     viewer hides the artist link), and the curtain labels the hang as a
 *     sample exhibition.
 *   - GRACEFUL: an unknown slug (admin-created venue without a curated
 *     hang) falls back to a balanced draw from the shared collection, so
 *     EVERY venue is walkable — the chooser test must never 404 on curation
 *     gaps. Missing image files degrade to the viewer's built-in
 *     placeholder texture; the room still renders.
 */
class SampleExhibitionService
{
    /**
     * Build the GALLERY_DATA.images payload for a venue's sample exhibition.
     *
     * @return array[] Array of artwork entries shaped exactly like the
     *                 entries GalleryViewController::show() emits for real
     *                 galleries (same keys the 3D runtime consumes), so the
     *                 preview exercises the identical render path.
     */
    public function forVenue(VenueTemplate $venue): array
    {
        $config    = config('sample_exhibitions');
        $artworks  = $config['collection']['artworks'] ?? [];
        $selection = $config['venues'][$venue->slug]['selection'] ?? null;

        // No curated hang for this slug (admin-created venue) — fall back to
        // a balanced draw so the venue is still walkable.
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

            // One file per work — the same URL for every texture tier. The
            // files are modest (~150–400 KB) and shared across all venues,
            // so after the first preview the browser cache serves every
            // subsequent one (preview fps parity requirement, P1.1).
            $url = asset('assets/sample/artworks/' . $file);

            $images[] = array_filter([
                // Preview ids are namespaced — tests assert no numeric
                // (real Image row) ids can appear in a preview payload.
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

                // No `artist` key on purpose: the viewer's
                // window.updateArtworkMeta hides the artist link when the
                // key is absent, so samples can never link to a real (or
                // broken) artist profile.

                'externalUrl' => null,
            ], fn ($v) => $v !== null);
        }

        return $images;
    }

    /**
     * The curated note for a venue's hang (curtain subtitle). Null when the
     * venue has no curated entry.
     */
    public function noteFor(VenueTemplate $venue): ?string
    {
        return config('sample_exhibitions.venues.' . $venue->slug . '.note');
    }

    /**
     * The credit line for the sample collection (curtain label).
     */
    public function credit(): string
    {
        return config('sample_exhibitions.collection.credit', 'Sample exhibition — demonstration artworks');
    }
}
