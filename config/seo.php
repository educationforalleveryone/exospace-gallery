<?php

declare(strict_types=1);

return [

    'site_name'        => env('APP_NAME', 'Exospace'),
    'title_separator'  => ' | ',

    'templates'        => [
        'home'          => '{site} — Immersive 3D Art Galleries',
        'gallery'       => '{title} — 3D Virtual Exhibition',
        'artist'        => '{title} — Artist Profile & 3D Exhibitions',
        'artwork'       => '{title} by {artist}',
        'venue'         => '{title} — 3D Venue Templates',
        'artists_hub'   => 'Browse Artists — 3D Exhibition Artists',
        'venues_hub'    => 'Venue Templates for 3D Exhibitions',
        'discover'      => 'Discover 3D Art Exhibitions',
        'default'       => '{title}',
    ],

    'default_description' => 'Create museum-quality 3D art exhibitions in minutes. Upload your images, pick a venue, share a link. Free to start.',

    'limits'           => [
        'title'            => 60,   // px-truncated ~580px; 60 chars is the safe ceiling
        'description'      => 155,  // 160 hard cap, 155 with ellipsis safety
        'og_title'         => 70,
        'og_description'   => 150,
    ],

    'artwork_gate'     => [
        'min_description_chars' => 80,
        'max_related'           => 6,
    ],

    'related'          => [
        'galleries_max' => 6,   // related exhibitions on a gallery page
        'artists_max'   => 6,   // related artists on an artist page
        'artworks_max'  => 6,   // related works on an artwork page
    ],

    'sitemap'          => [
        'per_page'      => 2000,

        'cache_ttl'     => 1800,   // 30 minutes
        'cache_ttl_stale' => 3600, // flexible-cache stale window

        // Gallery image sitemap entries (image:image extension).
        'include_images' => true,
    ],

    'feed'             => [
        'max_items' => 50,
    ],

    'og'               => [
        'default_image'        => 'img/og-default.png',
        'default_image_width'  => 1200,
        'default_image_height' => 630,
        'image_type'           => 'image/png',
        'locale'               => 'en_US',
        'twitter_card'         => 'summary_large_image',
    ],

    'canonical'        => [
        'stripped_params' => [
            // Universal tracking
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
            'utm_content', 'utm_id', 'utm_name', 'utm_source_platform',
            'gclid', 'gclsrc', 'gbraid', 'wbraid', 'dclid', 'fbclid',
            'msclkid', 'mc_cid', 'mc_eid', 'igshid', 'igsh', 'twclid',
            'ttclid', 'li_fat_id', 's', 'ref', 'ref_src', 'ref_url',
            '_ga', '_gl', 'vero_id', 'oly_enc_id', 'oly_anon_id',
            // Exospace display params (do not change content identity)
            'embed', 'artwork', 'preview',
        ],

        'pagination_param' => 'page',
    ],

    'robots'           => [
        'disallow' => [
            '/admin',
            '/master-control',
            '/profile',
            '/billing',
            '/login',
            '/register',
            '/forgot-password',
            '/team-invitations',
            '/finalize-installation',
            '/db-check',
            '/debug-render-test',
            '/unsubscribe',
            '/metrics',
            '/storage',
            '/auth',
            '/gallery/*/pin',
            '/gallery/*/track',
            '/gallery/*/og-image',
            '/gallery/*/qr',
            '/artist/*/og-image',
        ],
        'disallow_query' => [
            '/*?embed=',
            '/*?preview=',
        ],
    ],

    'pages'            => [
        'editorial_prefix'  => 'resources',

        'landing_cache_ttl' => 3600,
        'list_cache_ttl'    => 600,
    ],

    'audit'            => [
        'schedule'        => 'daily',
        'slack_on_issues' => true,
    ],
];
