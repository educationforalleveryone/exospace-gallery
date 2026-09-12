<?php

return [

    'collection' => [
        'name'   => 'The Exospace Sample Collection',
        'credit' => 'Demonstration artworks — not for sale',

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
            'note'      => 'Tall vertical works that answer the arcade and its oculus light.',
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

        'the-salon' => [
            'note'      => 'A close-hung domestic mix — portraits, studies and small landscapes at salon distance.',
            'selection' => [
                'night-window', 'harbour-light', 'ascending-figure', 'quiet-field',
                'vertical-chorus', 'dawn-lattice', 'cathedral-static', 'stone-arrangement',
            ],
        ],
    ],
];
