<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
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
    private const GROUPS = ['static', 'galleries', 'artists', 'artworks', 'content'];

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
        $version = $this->version();

        $groups = Cache::flexible(
            "sitemap:index:v{$version}",
            [now()->addMinutes(15), now()->addMinutes(30)],
            fn () => $this->buildIndexEntries($perPage),
        );

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

        $version = $this->version();
        $entries = Cache::flexible(
            "sitemap:group:{$group}:{$page}:v{$version}",
            [now()->addSeconds((int) config('seo.sitemap.cache_ttl', 1800)), now()->addSeconds((int) config('seo.sitemap.cache_ttl_stale', 3600))],
            fn () => $this->buildGroupEntries($group, $page, $perPage),
        );

        return $this->xmlResponse('sitemap', [
            'entries' => $entries,
        ]);
    }

    /**
     * LEGACY route: GET /sitemap-{page}.xml → 301 to the galleries group.
     * Keeps external references (Search Console, backlinks) working.
     */
    public function legacy(int $page): Response
    {
        if ($page < 1) {
            abort(404);
        }

        return redirect()->to("/sitemap-galleries-{$page}.xml", 301)
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * RSS 2.0 feed of recently-updated public exhibitions.
     */
    public function feed(): Response
    {
        $maxItems = (int) config('seo.feed.max_items', 50);

        $galleries = Cache::flexible('feed:galleries:v' . $this->version(), [now()->addMinutes(30), now()->addMinutes(60)], function () use ($maxItems) {
            return Gallery::publiclyViewable()
                ->with(['coverImage', 'user', 'venueTemplate'])
                ->withCount('images')
                ->has('images', '>=', 1)
                ->orderByDesc('updated_at')
                ->limit($maxItems)
                ->get();
        });

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
            if ($total === 0 && $group === 'content') {
                continue; // don't list an empty content group
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
        return Cache::flexible(
            'sitemap:count:' . $group . ':v' . $this->version(),
            [now()->addSeconds((int) config('seo.sitemap.cache_ttl', 1800)), now()->addSeconds((int) config('seo.sitemap.cache_ttl_stale', 3600))],
            fn () => match ($group) {
                'static' => count($this->staticPages()),
                'galleries' => $this->gallerySitemapQuery()->count(),
                'artists' => $this->artistSitemapQuery()->count(),
                'artworks' => $this->artworkSitemapQuery()->count(),
                'content' => $this->contentSitemapQuery()->count(),
                default => 0,
            },
        );
    }

    private function groupLastmod(string $group): ?string
    {
        return Cache::flexible(
            'sitemap:lastmod:' . $group . ':v' . $this->version(),
            [now()->addMinutes(10), now()->addMinutes(20)],
            function () use ($group) {
                $value = match ($group) {
                    'galleries' => $this->gallerySitemapQuery()->max('updated_at'),
                    'artists' => $this->artistSitemapQuery()->max('updated_at'),
                    'artworks' => $this->artworkSitemapQuery()->max('gallery_images.updated_at'),
                    'content' => $this->contentSitemapQuery()->max('updated_at'),
                    default => null,
                };

                return $value ? \Illuminate\Support\Carbon::parse($value)->toIso8601String() : now()->toIso8601String();
            },
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
            ['url' => url('/changelog'), 'priority' => '0.5', 'changefreq' => 'monthly'],
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
        return GalleryImage::query()
            ->whereHas('gallery', fn ($q) => $q->publiclyViewable())
            ->where(function ($q) {
                $q->whereNotNull('title')->orWhereNotNull('original_name');
            })
            ->where(function ($q) {
                $q->whereRaw('CHAR_LENGTH(COALESCE(description, \'\')) >= ?', [(int) config('seo.artwork_gate.min_description_chars', 80)])
                    ->orWhereNotNull('medium')
                    ->orWhereNotNull('year')
                    ->orWhereNotNull('artist_id');
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
