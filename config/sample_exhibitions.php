<?php

/**
 * Iteration 1 "The Rehearsal" (roadmap P1.1) — sample exhibition data.
 *
 * WHAT THIS IS
 * ------------
 * Every walkable venue preview (route `venues.preview`, view
 * venues/preview.blade.php) hangs a small, curated set of DEMONSTRATION
 * artworks so a prospective customer can experience the venue before
 * committing (the chooser test: every venue walkable pre-commit).
 *
 * The artworks are deliberately NOT real customer data, NOT seeded into the
 * galleries/images tables, and NOT reachable from any user-facing model.
 * They are built per-request into the preview GALLERY_DATA payload by
 * App\Services\SampleExhibitionService and rendered by the same 3D runtime
 * a paying customer's gallery uses. No database rows are created or read
 * for them.
 *
 * ART-TYPE MATCHING
 * -----------------
 * Roadmap §6/§8: a venue's sample hang must flatter the venue's art type —
 * Crystal Cathedral gets tall vertical works, Industrial Loft gets wide
 * landscapes, White Cube gets a balanced minimal mix, and so on. Each
 * venue's `selection` is an ORDERED list of collection keys (first work =
 * first hang position; placement order follows the viewer's ArtworkPlacer).
 *
 * SWAPPING IN CC0 WORKS LATER
 * ---------------------------
 * The bundled JPGs under public/assets/sample/artworks/ are generated
 * demonstration pieces. To upgrade the previews to real CC0 works (Met
 * Open Access, Art Institute of Chicago, Smithsonian Open Access — all
 * CC0), replace the files keeping the SAME filenames, or add entries here
 * with new keys + files and update the selections. Zero code changes.
 *
 * HONESTY RULES (Iteration 0 contract, still in force)
 * ----------------------------------------------------
 *   - Preview artworks never claim to be for sale (for_sale false).
 *   - The preview curtain labels the hang as a sample exhibition.
 *   - No artwork entry may reference a real artist profile (the `artist`
 *     key is intentionally absent → the viewer hides the artist link).
 */

return [

    'collection' => [
        'name'   => 'The Exospace Sample Collection',
        'credit' => 'Demonstration artworks — not for sale',

        // The shared pool of sample works. Every venue selects from here,
        // so the preview payload stays small (6–8 textures, all browser-
        // cacheable across venues) while each venue still reads as a
        // distinct, art-type-matched hang.
        'artworks' => [

            // ── Landscape (3:2) — wide walls, corridors, promenades ─────
            'harbour-light' => [
                'file'        => 'harbour-light.jpg',
                'width'       => 1920, 'height' => 1280,
                'orientation' => 'landscape',
                'title'       => 'Harbour Light, Late Season',
                'description' => 'Cool grey-blue bands over a quiet harbour — a study in restrained daylight.',
                'medium'      => 'Oil on linen',
                'year'        => '2021',
                'dimensions'  => '120 × 80 cm',
            ],
            'dawn-lattice' => [
                'file'        => 'dawn-lattice.jpg',
                'width'       => 1920, 'height' => 1280,
                'orientation' => 'landscape',
                'title'       => 'Dawn Lattice',
                'description' => 'A warm geometric grid caught between night and morning.',
                'medium'      => 'Acrylic and graphite on panel',
                'year'        => '2022',
                'dimensions'  => '120 × 80 cm',
            ],
            'tide-memorandum' => [
                'file'        => 'tide-memorandum.jpg',
                'width'       => 1920, 'height' => 1280,
                'orientation' => 'landscape',
                'title'       => 'Tide Memorandum',
                'description' => 'Layered teal strata — the record of a shoreline that keeps rewriting itself.',
                'medium'      => 'Oil on linen',
                'year'        => '2020',
                'dimensions'  => '120 × 80 cm',
            ],
            'north-field' => [
                'file'        => 'north-field.jpg',
                'width'       => 1920, 'height' => 1280,
                'orientation' => 'landscape',
                'title'       => 'North Field',
                'description' => 'A muted green-and-gold horizon, held very still.',
                'medium'      => 'Acrylic on canvas',
                'year'        => '2023',
                'dimensions'  => '120 × 80 cm',
            ],

            // ── Portrait (2:3) — tall walls, bays, colonnades ────────────
            'vertical-chorus' => [
                'file'        => 'vertical-chorus.jpg',
                'width'       => 1280, 'height' => 1920,
                'orientation' => 'portrait',
                'title'       => 'Vertical Chorus',
                'description' => 'Columns of violet rising through a hushed register.',
                'medium'      => 'Oil on linen',
                'year'        => '2022',
                'dimensions'  => '80 × 120 cm',
            ],
            'ascending-figure' => [
                'file'        => 'ascending-figure.jpg',
                'width'       => 1280, 'height' => 1920,
                'orientation' => 'portrait',
                'title'       => 'Ascending Figure',
                'description' => 'An elongated form climbing out of warm shadow.',
                'medium'      => 'Charcoal and pastel on paper',
                'year'        => '2021',
                'dimensions'  => '80 × 120 cm',
            ],
            'cathedral-static' => [
                'file'        => 'cathedral-static.jpg',
                'width'       => 1280, 'height' => 1920,
                'orientation' => 'portrait',
                'title'       => 'Cathedral Static',
                'description' => 'Vertical shafts of light against a deep, patient ground.',
                'medium'      => 'Oil and mixed media on panel',
                'year'        => '2023',
                'dimensions'  => '80 × 120 cm',
            ],
            'night-window' => [
                'file'        => 'night-window.jpg',
                'width'       => 1280, 'height' => 1920,
                'orientation' => 'portrait',
                'title'       => 'Night Window',
                'description' => 'A single luminous rectangle in deep blue — the hour rooms keep to themselves.',
                'medium'      => 'Oil on canvas',
                'year'        => '2020',
                'dimensions'  => '80 × 120 cm',
            ],

            // ── Square (1:1) — minimal hangs, easels, feature walls ─────
            'quiet-field' => [
                'file'        => 'quiet-field.jpg',
                'width'       => 1600, 'height' => 1600,
                'orientation' => 'square',
                'title'       => 'Quiet Field',
                'description' => 'An off-white field and one deliberate mark.',
                'medium'      => 'Mineral pigment on panel',
                'year'        => '2024',
                'dimensions'  => '100 × 100 cm',
            ],
            'signal-bloom' => [
                'file'        => 'signal-bloom.jpg',
                'width'       => 1600, 'height' => 1600,
                'orientation' => 'square',
                'title'       => 'Signal Bloom',
                'description' => 'Neon rings propagating across charcoal — interference as flora.',
                'medium'      => 'Acrylic and spray on canvas',
                'year'        => '2023',
                'dimensions'  => '100 × 100 cm',
            ],
            'stone-arrangement' => [
                'file'        => 'stone-arrangement.jpg',
                'width'       => 1600, 'height' => 1600,
                'orientation' => 'square',
                'title'       => 'Stone Arrangement',
                'description' => 'Grey forms balanced in the oldest composition there is.',
                'medium'      => 'Graphite and gesso on panel',
                'year'        => '2022',
                'dimensions'  => '100 × 100 cm',
            ],
            'slow-nebula' => [
                'file'        => 'slow-nebula.jpg',
                'width'       => 1600, 'height' => 1600,
                'orientation' => 'square',
                'title'       => 'Slow Nebula',
                'description' => 'A dust of violet and ember drifting across a dark square.',
                'medium'      => 'Oil glaze on linen',
                'year'        => '2021',
                'dimensions'  => '100 × 100 cm',
            ],
        ],
    ],

    /*
    │ Per-venue curated hangs (roadmap P1.1: "art-type-matched").
    │ `selection` is ordered — the first key is the first hang position.
    │ `note` documents the curatorial rationale (also surfaced on the
    │ preview curtain as the sample-hang subtitle).
    │
    │ Unknown slugs (admin-created venues) fall back to a balanced default
    │ draw in SampleExhibitionService — previews work for every venue.
    */
    'venues' => [

        'white-cube' => [
            'note'      => 'A balanced minimal hang — mixed orientations on clean white walls.',
            'selection' => [
                'quiet-field', 'harbour-light', 'vertical-chorus', 'stone-arrangement',
                'dawn-lattice', 'night-window', 'tide-memorandum', 'signal-bloom',
            ],
        ],

        'infinite-void' => [
            'note'      => 'Quiet, luminous works that hold their own in open blackness.',
            'selection' => [
                'quiet-field', 'slow-nebula', 'vertical-chorus',
                'harbour-light', 'stone-arrangement', 'night-window',
            ],
        ],

        'industrial-loft' => [
            'note'      => 'Wide landscapes and bold geometry against raw concrete.',
            'selection' => [
                'dawn-lattice', 'north-field', 'signal-bloom', 'harbour-light',
                'tide-memorandum', 'stone-arrangement', 'quiet-field', 'vertical-chorus',
            ],
        ],

        'dark-museum' => [
            'note'      => 'A classical hang — portraits and tonal studies under warm spots.',
            'selection' => [
                'ascending-figure', 'night-window', 'harbour-light', 'vertical-chorus',
                'north-field', 'tide-memorandum', 'cathedral-static', 'quiet-field',
            ],
        ],

        'zen-gallery' => [
            'note'      => 'Calm squares and soft horizons for a contemplative read.',
            'selection' => [
                'quiet-field', 'stone-arrangement', 'tide-memorandum',
                'north-field', 'slow-nebula', 'harbour-light',
            ],
        ],

        'crystal-cathedral' => [
            'note'      => 'Tall vertical works that answer the shard ring above.',
            'selection' => [
                'cathedral-static', 'vertical-chorus', 'ascending-figure', 'night-window',
                'slow-nebula', 'harbour-light', 'dawn-lattice', 'tide-memorandum',
            ],
        ],

        'nebula-drift' => [
            'note'      => 'Cosmic tonalities that dissolve into the drift.',
            'selection' => [
                'slow-nebula', 'signal-bloom', 'vertical-chorus',
                'night-window', 'quiet-field', 'cathedral-static',
            ],
        ],

        'luxury-penthouse' => [
            'note'      => 'Moody portraits and cool landscapes for a collector’s floor.',
            'selection' => [
                'night-window', 'harbour-light', 'vertical-chorus',
                'dawn-lattice', 'quiet-field', 'ascending-figure',
            ],
        ],

        'cyber-gallery' => [
            'note'      => 'High-contrast geometry and neon registers along the grid.',
            'selection' => [
                'signal-bloom', 'night-window', 'dawn-lattice', 'slow-nebula',
                'harbour-light', 'vertical-chorus', 'stone-arrangement', 'quiet-field',
            ],
        ],

        'sculpture-garden' => [
            'note'      => 'Airy landscapes and minimal squares among the trees.',
            'selection' => [
                'north-field', 'quiet-field', 'tide-memorandum',
                'dawn-lattice', 'stone-arrangement', 'harbour-light',
            ],
        ],

        'mirror-lake' => [
            'note'      => 'Dark, quiet works that surface slowly out of the mist.',
            'selection' => [
                'night-window', 'slow-nebula', 'quiet-field',
                'vertical-chorus', 'tide-memorandum', 'ascending-figure',
            ],
        ],

        // Iteration 8 "The Salon" (roadmap P3.2): the hang is DELIBERATELY
        // orientation-mixed — portraits interleaved with landscapes and
        // squares — because the salon declares placement.pair_orientation
        // (§6.4): the preview exercises the IT6 pairing machinery exactly
        // as a customer's mixed upload would read on its walls.
        'the-salon' => [
            'note'      => 'A close-hung domestic mix — portraits, studies and small landscapes at salon distance.',
            'selection' => [
                'night-window', 'harbour-light', 'ascending-figure', 'quiet-field',
                'vertical-chorus', 'dawn-lattice', 'cathedral-static', 'stone-arrangement',
            ],
        ],
    ],
];
