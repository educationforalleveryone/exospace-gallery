<?php

declare(strict_types=1);

/**
 * Central SEO configuration for Exospace.
 *
 * This is the single place where SEO behaviour is configured. Nothing in
 * templates or controllers may hard-code SEO limits, titles or defaults —
 * they read from here (usually via App\Support\Seo\SeoManager).
 *
 * The design goal: when keyword research arrives later, the *strategy*
 * lives in data (seo_profiles / seo_pages), not in templates. This config
 * holds only mechanical parameters.
 */

return [

    // ── Brand / site identity ───────────────────────────────────────────
    'site_name'        => env('APP_NAME', 'Exospace'),
    'title_separator'  => ' | ',

    // Title templates. Placeholders: {title}, {site}, {section}.
    // Kept as templates (not hard-coded strings) so the brand voice can
    // change in one place. Entity pages compose via SeoManager.
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

    // Default meta description used when a page has no better source.
    // This is a platform-level description of the product, not a keyword
    // strategy.
    'default_description' => 'Create museum-quality 3D art exhibitions in minutes. Upload your images, pick a venue, share a link. Free to start.',

    // ── Meta limits ─────────────────────────────────────────────────────
    'limits'           => [
        'title'            => 60,   // px-truncated ~580px; 60 chars is the safe ceiling
        'description'      => 155,  // 160 hard cap, 155 with ellipsis safety
        'og_title'         => 70,
        'og_description'   => 150,
    ],

    // ── Quality gates ───────────────────────────────────────────────────
    // An artwork only gets an indexable standalone page when it carries
    // real information. These thresholds are mechanical, not editorial.
    'artwork_gate'     => [
        'min_description_chars' => 80,
        'max_related'           => 6,
    ],

    'related'          => [
        'galleries_max' => 6,   // related exhibitions on a gallery page
        'artists_max'   => 6,   // related artists on an artist page
        'artworks_max'  => 6,   // related works on an artwork page
    ],

    // ── Sitemaps ────────────────────────────────────────────────────────
    'sitemap'          => [
        // URLs per sub-sitemap. Google caps at 50,000 URLs / 50 MB uncompressed;
        // 2,000 keeps XML documents comfortably small while limiting file count.
        'per_page'      => 2000,

        // Cache TTLs (seconds) for sub-sitemaps and the index. Entries are
        // also invalidated by observers bumping the sitemap version key.
        'cache_ttl'     => 1800,   // 30 minutes
        'cache_ttl_stale' => 3600, // flexible-cache stale window

        // Gallery image sitemap entries (image:image extension).
        'include_images' => true,
    ],

    // ── Feeds ───────────────────────────────────────────────────────────
    'feed'             => [
        'max_items' => 50,
    ],

    // ── Open Graph ──────────────────────────────────────────────────────
    'og'               => [
        'default_image'        => 'img/og-default.png',
        'default_image_width'  => 1200,
        'default_image_height' => 630,
        'image_type'           => 'image/png',
        'locale'               => 'en_US',
        'twitter_card'         => 'summary_large_image',
    ],

    // ── Canonicalisation ────────────────────────────────────────────────
    'canonical'        => [
        // Query params that NEVER affect page content — always stripped
        // from canonical URLs. Tracking params first, then app-specific
        // display params.
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

        // Params that DO change content and are preserved when they are
        // the sole content-affecting param (e.g. pagination).
        'pagination_param' => 'page',
    ],

    // ── Robots / crawling ───────────────────────────────────────────────
    'robots'           => [
        // Paths disallowed for all user agents on the primary host.
        // Admin/auth/billing/utility surfaces + duplicate/preview endpoints.
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
        // Wildcard query disallows (Google/Bing extension syntax).
        'disallow_query' => [
            '*?embed=',
            '*?preview=',
        ],
    ],

    // ── SEO pages (landing + editorial) ─────────────────────────────────
    'pages'            => [
        // URL prefix for editorial content (guides, tutorials, comparisons).
        // Landing pages live at the root (/{slug}); editorial content is
        // namespaced to avoid collisions with future product routes.
        'editorial_prefix'  => 'resources',

        // Landing-page slugs are matched against a cached allow-list, so
        // root-slug routing can never shadow real product routes.
        'landing_cache_ttl' => 3600,
        'list_cache_ttl'    => 600,
    ],

    // ── Indexability reporting ──────────────────────────────────────────
    'audit'            => [
        // Scheduled SEO health check. Results go to the log and (when the
        // operational webhook is configured) to Slack.
        'schedule'        => 'daily',
        'slack_on_issues' => true,
    ],
];
