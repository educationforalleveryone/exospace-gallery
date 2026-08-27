<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\GalleryScheduleEvent;
use App\Models\SeoProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

/**
 * SEO Operating System — sitemap system (Iteration 4).
 *
 * Architecture (replaces the galleries-only sitemap of S-4):
 *
 *   /sitemap.xml                    → index: one entry per GROUP (+page)
 *   /sitemap-{group}-{page}.xml     → group sub-sitemaps
 *   /sitemap-{page}.xml             → LEGACY gallery sitemaps → 301 to the
 *                                     new /sitemap-galleries-{page}.xml
 *
 * Groups:
 *   static      — fixed marketing/legal pages (single page)
 *   galleries   — publiclyViewable, non-empty exhibitions
 *   artists     — artists with >= 1 public work
 *   artworks    — artworks passing the quality gate in public galleries
 *   events      — galleries with >= 1 upcoming active event (ITERATION 5;
 *                 the RSVP surface — openings/artist talks — that search
 *                 engines otherwise never discover)
 *   content     — published seo_pages (editorial + landing; empty until
 *                 Iteration 5 data exists — guarded by Schema::hasTable)
 *
 * Scale design:
 *   - 2,000 URLs per sub-sitemap (config seo.sitemap.per_page)
 *   - Every sub-sitemap cached with a VERSION suffix; observers bump the
 *     version on entity writes so caches regenerate lazily (no storms).
 *   - Index <lastmod> is the real max(updated_at) per group, not now().
 *   - Out-of-range pages 404 (Google Search Console hygiene).
 *   - Custom-domain hosts get a SINGLE-ENTRY sitemap for the resolved
 *     gallery (cross-host sitemap references are invalid — audit M5).
 *
 * Inclusion rules:
 *   - Only canonical, indexable pages. SeoProfile overrides:
 *     sitemap_include=false forces exclusion; sitemap_include=true can
 *     override the thin-content (empty gallery) rule but NEVER the
 *     publiclyViewable access gate — private content stays out, period.
 */
class SitemapController extends Controller
{
    /** Groups served by this controller. */
    private const GROUPS = ['static', 'galleries', 'artists', 'artworks', 'events', 'content'];

    public function index(Request $request): Response
    {
        // Custom-domain host: single-entry sitemap for the resolved gallery.
        $resolved = $request->attributes->get('resolved_gallery');
        if ($resolved) {
            return $this->xmlResponse('sitemap', [
                'entries' => [[
                    'loc' => $resolved->public_url,
                    'lastmod' => $resolved->updated_at?->toIso8601String(),
                    'changefreq' => 'weekly',
                    'priority' => '1.0',
                ]],
            ]);
        }

        $perPage = (int) config('seo.sitemap.per_page', 2000);

        $groups = $this->cacheIndexEntries($perPage);

        return $this->xmlResponse('sitemap-index', [
            'groups' => $groups,
        ]);
    }

    /**
     * Route: GET /sitemap-{group}-{page}.xml
     */
    public function group(Request $request, string $group, int $page): Response
    {
        if (!in_array($group, self::GROUPS, true)) {
            abort(404);
        }
        if ($page < 1) {
            abort(404);
        }

        $perPage = (int) config('seo.sitemap.per_page', 2000);
        $total = $this->groupCount($group);

        $pages = (int) ceil($total / $perPage);
        if ($page > max($pages, 1)) {
            // Out-of-range pages must 404, not serve empty sitemaps.
            abort(404);
        }

        $entries = $this->cacheGroupEntries($group, $page, $perPage);

        return $this->xmlResponse('sitemap', [
            'entries' => $entries,
        ]);
    }

    /**
     * LEGACY route: GET /sitemap-{page}.xml → 301 to the galleries group.
     * Keeps external references (Search Console, backlinks) working.
     */
    public function legacy(int $page): \Illuminate\Http\RedirectResponse
    {
        if ($page < 1) {
            abort(404);
        }

        // ITERATION-1 FIX: the return type was Response but a RedirectResponse
        // is returned — PHP TypeError → 500 on every legacy sitemap URL that
        // still receives Search Console / backlink traffic.
        return redirect()->to("/sitemap-galleries-{$page}.xml", 301)
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * RSS 2.0 feed of recently-updated public exhibitions.
     */
    public function feed(): Response
    {
        $maxItems = (int) config('seo.feed.max_items', 50);

        $galleries = $this->cacheFeedGalleries($maxItems);

        $response = response()->view('feed', compact('galleries'))
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');

        return $response->header('Cache-Control', 'public, max-age=1800');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Index construction
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<int, array{group: string, page: int, lastmod: ?string}>
     */
    private function buildIndexEntries(int $perPage): array
    {
        $entries = [];

        foreach (self::GROUPS as $group) {
            $total = $this->groupCount($group);
            if ($total === 0 && in_array($group, ['content', 'events'], true)) {
                // Both groups have time-based membership that can genuinely
                // be empty (no editorial pages yet; no upcoming events this
                // week). Listing an empty sitemap in the index is a Search
                // Console warning, not a feature — structural groups
                // (galleries/artists/artworks) stay listed.
                continue;
            }
            $pages = max(1, (int) ceil($total / $perPage));
            $lastmod = $this->groupLastmod($group);

            for ($page = 1; $page <= $pages; $page++) {
                $entries[] = [
                    'group' => $group,
                    'page' => $page,
                    'lastmod' => $lastmod,
                ];
            }
        }

        return $entries;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Group queries
    // ─────────────────────────────────────────────────────────────────────

    private function groupCount(string $group): int
    {
        $version = $this->version();

        // HOTFIX: Cache::flexible() is backed by the Redis cache store here.
        // Laravel's Redis driver stores plain numeric values without PHP
        // serialization (to keep atomic incr/decr working), and Redis always
        // returns raw values as strings — so a cache HIT for a numeric value
        // comes back as e.g. "15" (string), not 15 (int). The closure below
        // always returns a real int on a cache MISS, but the declared `: int`
        // return type then throws on every cache HIT. Casting explicitly
        // fixes both paths identically.
        return (int) Cache::flexible(
            'sitemap:count:' . $group . ':v' . $version,
            [now()->addSeconds((int) config('seo.sitemap.cache_ttl', 1800)), now()->addSeconds((int) config('seo.sitemap.cache_ttl_stale', 3600))],
            fn () => match ($group) {
                'static' => count($this->staticPages()),
                'galleries' => $this->gallerySitemapQuery()->count(),
                'artists' => $this->artistSitemapQuery()->count(),
                'artworks' => $this->artworkSitemapQuery()->count(),
                'events' => $this->eventGallerySitemapQuery()->count(),
                'content' => $this->contentSitemapQuery()->count(),
                default => 0,
            },
            ['seconds' => 30],
        );
    }

    private function groupLastmod(string $group): ?string
    {
        $version = $this->version();

        return Cache::flexible(
            'sitemap:lastmod:' . $group . ':v' . $version,
            [now()->addMinutes(10), now()->addMinutes(20)],
            function () use ($group) {
                $value = match ($group) {
                    'galleries' => $this->gallerySitemapQuery()->max('updated_at'),
                    'artists' => $this->artistSitemapQuery()->max('updated_at'),
                    'artworks' => $this->artworkSitemapQuery()->max('gallery_images.updated_at'),
                    'events' => GalleryScheduleEvent::query()
                        ->active()
                        ->where('starts_at', '>', now())
                        ->whereHas('gallery', fn ($q) => $this->applyEventGalleryAccessRules($q))
                        ->max('updated_at'),
                    'content' => $this->contentSitemapQuery()->max('updated_at'),
                    default => null,
                };

                return $value ? \Illuminate\Support\Carbon::parse($value)->toIso8601String() : now()->toIso8601String();
            },
            ['seconds' => 30],
        );
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function buildGroupEntries(string $group, int $page, int $perPage): array
    {
        return match ($group) {
            'static' => $this->staticPages(),
            'galleries' => $this->galleryEntries($page, $perPage),
            'artists' => $this->artistEntries($page, $perPage),
            'artworks' => $this->artworkEntries($page, $perPage),
            'events' => $this->eventEntries($page, $perPage),
            'content' => $this->contentEntries($page, $perPage),
            default => [],
        };
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function staticPages(): array
    {
        $pages = [
            ['url' => url('/'), 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['url' => url('/discover'), 'priority' => '0.9', 'changefreq' => 'daily'],
            ['url' => url('/artists'), 'priority' => '0.8', 'changefreq' => 'daily'],
            ['url' => url('/venues'), 'priority' => '0.7', 'changefreq' => 'weekly'],
            ['url' => url('/pricing'), 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['url' => url('/about'), 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['url' => url('/contact'), 'priority' => '0.6', 'changefreq' => 'monthly'],
            // ITERATION-1 FIX: named routes (the source-scan contract) and
            // /status added — a public status/uptime page in the sitemap is
            // a trust signal for galleries evaluating the platform.
            ['url' => route('changelog'), 'priority' => '0.5', 'changefreq' => 'monthly'],
            ['url' => route('status'), 'priority' => '0.4', 'changefreq' => 'daily'],
            ['url' => url('/privacy'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['url' => url('/terms'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['url' => url('/refund-policy'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['url' => url('/payment-security'), 'priority' => '0.3', 'changefreq' => 'yearly'],
        ];

        return array_map(fn ($p) => [
            'loc' => $p['url'],
            'priority' => $p['priority'],
            'changefreq' => $p['changefreq'],
            'lastmod' => null,
        ], $pages);
    }

    private function gallerySitemapQuery()
    {
        $excluded = $this->profileExclusions(Gallery::class);
        $includedThin = $this->profileInclusions(Gallery::class);

        return Gallery::query()
            ->publiclyViewable()
            ->where(function ($q) use ($excluded, $includedThin) {
                $q->has('images', '>=', 1);
                if ($includedThin !== []) {
                    // Admin-forced inclusion for empty-but-public galleries.
                    $q->orWhereIn('id', $includedThin);
                }
            })
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('id', $excluded))
            ->whereDoesntHave('user', fn ($q) => $q->whereNotNull('banned_at'));
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function galleryEntries(int $page, int $perPage): array
    {
        $includeImages = (bool) config('seo.sitemap.include_images', true);

        $galleries = $this->gallerySitemapQuery()
            ->with($includeImages ? ['coverImage'] : [])
            ->orderBy('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get(['id', 'slug', 'title', 'custom_domain', 'updated_at']);

        return $galleries->map(function ($gallery) {
            $entry = [
                'loc' => $gallery->public_url,
                'lastmod' => $gallery->updated_at?->toIso8601String(),
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ];
            if ($gallery->coverImage) {
                $entry['image'] = [
                    'loc' => asset($gallery->coverImage->path),
                    'title' => $gallery->title,
                ];
            }

            return $entry;
        })->all();
    }

    private function artistSitemapQuery()
    {
        $excluded = $this->profileExclusions(Artist::class);

        return Artist::query()
            ->whereHas('images.gallery', fn ($q) => $q->publiclyViewable())
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('id', $excluded));
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function artistEntries(int $page, int $perPage): array
    {
        return $this->artistSitemapQuery()
            ->orderBy('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get(['id', 'slug', 'updated_at'])
            ->map(fn ($artist) => [
                'loc' => url('/artist/' . $artist->slug),
                'lastmod' => $artist->updated_at?->toIso8601String(),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ])
            ->all();
    }

    /**
     * Artworks in publicly-viewable galleries that pass the quality gate
     * (mirrors ArtworkController::passesQualityGate in SQL).
     */
    private function artworkSitemapQuery()
    {
        // ITERATION-1 FIX (ambiguous column): artworkEntries() JOINs
        // galleries (for the slug), and both tables have a `title` column —
        // the unqualified where clauses made SQLite (and strict MySQL
        // configs) fail with "ambiguous column name: title" → 500 on
        // /sitemap-artworks-*.xml. Qualify every gallery_images column.
        return GalleryImage::query()
            ->whereHas('gallery', fn ($q) => $q->publiclyViewable())
            ->where(function ($q) {
                $q->whereNotNull('gallery_images.title')
                    ->orWhereNotNull('gallery_images.original_name');
            })
            ->where(function ($q) {
                $q->whereRaw('LENGTH(COALESCE(gallery_images.description, \'\')) >= ?', [(int) config('seo.artwork_gate.min_description_chars', 80)])
                    ->orWhereNotNull('gallery_images.medium')
                    ->orWhereNotNull('gallery_images.year')
                    ->orWhereNotNull('gallery_images.artist_id');
            });
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function artworkEntries(int $page, int $perPage): array
    {
        return $this->artworkSitemapQuery()
            ->join('galleries', 'galleries.id', '=', 'gallery_images.gallery_id')
            ->orderBy('gallery_images.id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get(['gallery_images.id', 'gallery_images.updated_at', 'galleries.slug as gallery_slug'])
            ->map(fn ($row) => [
                'loc' => url("/gallery/{$row->gallery_slug}/artwork/{$row->id}"),
                'lastmod' => $row->updated_at?->toIso8601String(),
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ])
            ->all();
    }

    /**
     * ITERATION 5 — events group: galleries whose /events page is worth
     * crawling, i.e. has at least one ACTIVE UPCOMING event.
     *
     * Access rules deliberately mirror PublicEventController::index (the
     * page this URL serves), NOT the publiclyViewable() scope:
     *   - is_active = true       (draft/unpublished → 404)
     *   - pin_hash IS NULL       (PIN galleries redirect to the PIN screen
     *                             — a sitemap entry would be a redirect URL)
     *   - not closed             (closed → redirect to the gallery page)
     *   - opens_at: UNRESTRICTED (a not-yet-open exhibition keeps its
     *                             events page public ON PURPOSE — openings
     *                             and artist talks are the pre-opening
     *                             marketing surface; that is what RSVPs
     *                             are for. See Iteration-3 gating notes.)
     *
     * Upcoming-only (starts_at > now) keeps the group self-pruning: as
     * events pass, their galleries drop out instead of accumulating
     * thin pages with only past events. Banned owners excluded, same
     * as the galleries group. SeoProfile exclusions respected.
     *
     * The URL set is per-GALLERY (one /gallery/{slug}/events entry), not
     * per-event — there are no per-event detail pages; the events page is
     * the indexable surface.
     */
    private function eventGallerySitemapQuery()
    {
        $excluded = $this->profileExclusions(Gallery::class);

        return Gallery::query()
            ->where(function ($q) {
                $this->applyEventGalleryAccessRules($q);
            })
            ->whereHas('scheduleEvents', fn ($q) => $q
                ->where('is_active', true)
                ->where('starts_at', '>', now()))
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('id', $excluded))
            ->whereDoesntHave('user', fn ($q) => $q->whereNotNull('banned_at'));
    }

    /**
     * Access predicate shared by the events group query and its lastmod
     * computation (which runs from the GalleryScheduleEvent side).
     */
    private function applyEventGalleryAccessRules($q)
    {
        $q->where('is_active', true)
            ->whereNull('pin_hash')
            ->where(function ($q) {
                $q->whereNull('closes_at')->orWhere('closes_at', '>', now());
            });

        return $q;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function eventEntries(int $page, int $perPage): array
    {
        $galleries = $this->eventGallerySitemapQuery()
            ->withMax(
                ['scheduleEvents' => fn ($q) => $q
                    ->where('is_active', true)
                    ->where('starts_at', '>', now())],
                'updated_at',
                'latest_event_update',
            )
            ->orderBy('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get(['id', 'slug', 'updated_at']);

        return $galleries->map(function ($gallery) {
            // Canonical main-domain URL: PublicEventController's SeoData
            // canonical is url('/gallery/{slug}/events') even when the
            // gallery itself serves from a custom domain (custom-domain
            // hosts get the single-entry gallery sitemap — see index()).
            return [
                'loc'        => url("/gallery/{$gallery->slug}/events"),
                'lastmod'    => $gallery->latest_event_update
                    ? \Illuminate\Support\Carbon::parse($gallery->latest_event_update)->toIso8601String()
                    : $gallery->updated_at?->toIso8601String(),
                'changefreq' => 'weekly',
                'priority'   => '0.6',
            ];
        })->all();
    }

    /**
     * Published seo_pages (Iteration 5). Guarded so Iteration 4 deploys
     * cleanly before the seo_pages migration runs.
     */
    private function contentSitemapQuery()
    {
        if (!\Schema::hasTable('seo_pages')) {
            return Gallery::query()->whereRaw('1 = 0'); // empty set, compatible builder
        }

        $excluded = $this->profileExclusions(\App\Models\SeoPage::class);

        return \App\Models\SeoPage::query()
            ->published()
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('id', $excluded));
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function contentEntries(int $page, int $perPage): array
    {
        if (!\Schema::hasTable('seo_pages')) {
            return [];
        }

        return $this->contentSitemapQuery()
            ->orderBy('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get(['id', 'slug', 'type', 'updated_at'])
            ->map(fn ($page) => [
                'loc' => $page->public_url,
                'lastmod' => $page->updated_at?->toIso8601String(),
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ])
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    // SeoProfile overrides
    // ─────────────────────────────────────────────────────────────────────

    /**
     * IDs of entities whose profile forces sitemap EXCLUSION.
     *
     * @param  class-string  $subjectType
     * @return array<int, int>
     */
    private function profileExclusions(string $subjectType): array
    {
        if (!\Schema::hasTable('seo_profiles')) {
            return [];
        }

        return SeoProfile::query()
            ->where('subject_type', $subjectType)
            ->where('sitemap_include', false)
            ->pluck('subject_id')
            ->all();
    }

    /**
     * IDs of entities whose profile forces sitemap INCLUSION (may override
     * the thin-content rule, never the access gate).
     *
     * @param  class-string  $subjectType
     * @return array<int, int>
     */
    private function profileInclusions(string $subjectType): array
    {
        if (!\Schema::hasTable('seo_profiles')) {
            return [];
        }

        return SeoProfile::query()
            ->where('subject_type', $subjectType)
            ->where('sitemap_include', true)
            ->pluck('subject_id')
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────
    // ITERATION 4 — cache accessors (single source of truth for keys/TTLs)
    // ─────────────────────────────────────────────────────────────────────
    //
    // Every sitemap cache read goes through one of these accessors. The
    // request path and sitemap:warm share them, so a warmed key can NEVER
    // diverge from the key a crawler request would read (same expression,
    // same TTLs). Each accessor also passes a 30s lock to Cache::flexible —
    // the stale-path refresh is serialized, so a burst of crawlers hitting
    // an expired-but-present entry triggers ONE background rebuild instead
    // of N concurrent ones. (The cold path still computes inline by design;
    // warming is what keeps it cold-free.)

    /**
     * @return array<int, array{group: string, page: int, lastmod: ?string}>
     */
    private function cacheIndexEntries(int $perPage): array
    {
        $version = $this->version();

        return Cache::flexible(
            "sitemap:index:v{$version}",
            [now()->addMinutes(15), now()->addMinutes(30)],
            fn () => $this->buildIndexEntries($perPage),
            ['seconds' => 30],
        );
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function cacheGroupEntries(string $group, int $page, int $perPage): array
    {
        $version = $this->version();

        return Cache::flexible(
            "sitemap:group:{$group}:{$page}:v{$version}",
            [now()->addSeconds((int) config('seo.sitemap.cache_ttl', 1800)), now()->addSeconds((int) config('seo.sitemap.cache_ttl_stale', 3600))],
            fn () => $this->buildGroupEntries($group, $page, $perPage),
            ['seconds' => 30],
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Gallery>
     */
    private function cacheFeedGalleries(int $maxItems)
    {
        return Cache::flexible(
            'feed:galleries:v' . $this->version(),
            [now()->addMinutes(30), now()->addMinutes(60)],
            fn () => Gallery::publiclyViewable()
                ->with(['coverImage', 'user', 'venueTemplate'])
                ->withCount('images')
                ->has('images', '>=', 1)
                ->orderByDesc('updated_at')
                ->limit($maxItems)
                ->get(),
            ['seconds' => 30],
        );
    }

    /**
     * ITERATION 4 — pre-populate the sitemap caches (sitemap:warm command,
     * daily 04:15 schedule, and post-rebuild hook).
     *
     * A cold cache key rebuilds synchronously INSIDE the crawler's request
     * (Cache::flexible's cold path has no lock) — at scale that means a
     * Googlebot hit pays a multi-second COUNT + 2,000-row query + Blade XML
     * render, and version bumps re-cold every key at once. Warming moves
     * that cost to a scheduled window; crawler requests become pure cache
     * reads.
     *
     * Page-addressed URLs (/sitemap-{group}-{page}.xml) require offset
     * pagination, so deep pages carry OFFSET cost — also absorbed here, in
     * the warmer, instead of in a crawler request.
     *
     * @param  string|null  $group  warm a single group, or all when null
     * @param  int  $maxPagesPerGroup  safety cap (pages beyond this stay
     *                                 lazy-warmed; 25 × 2000 = 50k URLs per
     *                                 group by default)
     * @return array{warmed: int, groups: array<string, int>, capped: bool}
     */
    public function warmCaches(?string $group = null, int $maxPagesPerGroup = 25): array
    {
        $groups = $group !== null
            ? (in_array($group, self::GROUPS, true) ? [$group] : [])
            : self::GROUPS;

        $perPage = (int) config('seo.sitemap.per_page', 2000);
        $stats = ['warmed' => 0, 'groups' => [], 'capped' => false];

        // 1. Counts + lastmods first — buildIndexEntries() reads them.
        foreach ($groups as $g) {
            $this->groupCount($g);
            $this->groupLastmod($g);
            $stats['warmed'] += 2;
        }

        // 2. Index document.
        $this->cacheIndexEntries($perPage);
        $stats['warmed']++;

        // 3. Group pages (capped).
        foreach ($groups as $g) {
            $total = $this->groupCount($g);
            $pages = max(1, (int) ceil($total / $perPage));
            if ($pages > $maxPagesPerGroup) {
                $pages = $maxPagesPerGroup;
                $stats['capped'] = true;
            }
            for ($page = 1; $page <= $pages; $page++) {
                $this->cacheGroupEntries($g, $page, $perPage);
                $stats['warmed']++;
            }
            $stats['groups'][$g] = $pages;
        }

        // 4. RSS feed.
        $this->cacheFeedGalleries((int) config('seo.feed.max_items', 50));
        $stats['warmed']++;

        return $stats;
    }

    /**
     * Cache version — observers bump it to lazily invalidate every
     * versioned sitemap key at once.
     */
    private function version(): int
    {
        return (int) Cache::get('seo:sitemap:version', 1);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function xmlResponse(string $view, array $data): Response
    {
        return response()
            ->view($view, $data)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=1800');
    }
}