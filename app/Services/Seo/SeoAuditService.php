<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\SeoPage;
use App\Models\SeoProfile;
use App\Models\SeoRedirect;
use Illuminate\Support\Collection;

/**
 * SEO health auditor (Iteration 6).
 *
 * Computes the platform's SEO health from REAL data — the same queries the
 * public surfaces use, so the report reflects what crawlers actually see.
 * Used by the super-admin dashboard and the scheduled `exospace:seo-audit`
 * command (issues optionally posted to Slack via the standard operational
 * webhook).
 *
 * This is a READ-ONLY service: no Search Console data is fabricated or
 * fetched — index performance lives in external tools (see master manual §3).
 */
class SeoAuditService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'indexable_galleries'  => Gallery::publiclyViewable()->has('images', '>=', 1)->count(),
            'indexable_artists'    => Artist::whereHas('images.gallery', fn ($q) => $q->publiclyViewable())->count(),
            'indexable_artworks'   => $this->indexableArtworkCount(),
            'published_seo_pages'  => \Schema::hasTable('seo_pages') ? SeoPage::published()->count() : 0,
            'active_redirects'     => \Schema::hasTable('seo_redirects') ? SeoRedirect::active()->count() : 0,
            'generated_at'         => now()->toIso8601String(),
        ];
    }

    /**
     * Issues = actionable quality problems. Each entry is a small, factual
     * finding; the dashboard links to the affected entity.
     *
     * @return array<int, array{key: string, label: string, count: int, severity: string}>
     */
    public function issues(): array
    {
        $issues = [];

        // 1. Public galleries with no description (meta description falls
        //    back to generated copy — better to have real curator text).
        $missingDesc = Gallery::publiclyViewable()->has('images', '>=', 1)
            ->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''))
            ->count();
        if ($missingDesc > 0) {
            $issues[] = [
                'key' => 'galleries_missing_description',
                'label' => "Public galleries with no curator description (fallback text used)",
                'count' => $missingDesc,
                'severity' => 'warning',
            ];
        }

        // 2. Artists with public works but no bio.
        $artistsNoBio = Artist::whereHas('images.gallery', fn ($q) => $q->publiclyViewable())
            ->where(fn ($q) => $q->whereNull('bio')->orWhere('bio', ''))
            ->count();
        if ($artistsNoBio > 0) {
            $issues[] = [
                'key' => 'artists_missing_bio',
                'label' => "Artists with public works but no biography",
                'count' => $artistsNoBio,
                'severity' => 'info',
            ];
        }

        // 3. Artworks failing the quality gate (page exists but noindex).
        $thinArtworks = $this->indexableGalleryImageQuery()
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('CHAR_LENGTH(COALESCE(description, \'\')) < 80'))
            ->whereNull('medium')->whereNull('year')->whereNull('artist_id')
            ->count();
        if ($thinArtworks > 0) {
            $issues[] = [
                'key' => 'thin_artworks',
                'label' => "Artworks below the quality gate (noindex — add metadata to index)",
                'count' => $thinArtworks,
                'severity' => 'info',
            ];
        }

        // 4. Profile-forced noindex on public galleries (intentional or not?).
        $forcedNoindex = 0;
        if (\Schema::hasTable('seo_profiles')) {
            $forcedNoindex = SeoProfile::query()
                ->where('subject_type', Gallery::class)
                ->where('robots_directive', 'like', 'noindex%')
                ->count();
        }
        if ($forcedNoindex > 0) {
            $issues[] = [
                'key' => 'galleries_forced_noindex',
                'label' => "Galleries with a profile-forced noindex",
                'count' => $forcedNoindex,
                'severity' => 'info',
            ];
        }

        // 5. Draft SEO pages older than 30 days (stale work in progress).
        if (\Schema::hasTable('seo_pages')) {
            $staleDrafts = SeoPage::where('status', 'draft')
                ->where('created_at', '<', now()->subDays(30))
                ->count();
            if ($staleDrafts > 0) {
                $issues[] = [
                    'key' => 'stale_seo_page_drafts',
                    'label' => "SEO page drafts untouched for 30+ days",
                    'count' => $staleDrafts,
                    'severity' => 'info',
                ];
            }
        }

        return $issues;
    }

    /**
     * Galleries with per-page SEO context for the dashboard table.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function galleryTable(string $search = '', string $filter = 'all')
    {
        $query = Gallery::query()
            ->with(['user', 'seoProfile', 'coverImage'])
            ->withCount('images')
            ->orderByDesc('updated_at');

        if ($search !== '') {
            $query->where(fn ($q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%"));
        }

        if ($filter === 'public') {
            $query->publiclyViewable()->has('images', '>=', 1);
        } elseif ($filter === 'issues') {
            $query->publiclyViewable()
                ->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''));
        }

        return $query->paginate(25)->withQueryString();
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function artistTable(string $search = '')
    {
        return Artist::query()
            ->with(['seoProfile'])
            ->withCount(['images as public_works_count' => fn ($q) => $q->whereHas('gallery', fn ($g) => $g->publiclyViewable())])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"))
            ->orderByDesc('public_works_count')
            ->paginate(25)
            ->withQueryString();
    }

    private function indexableArtworkCount(): int
    {
        return $this->indexableGalleryImageQuery()
            ->where(function ($q) {
                $q->whereRaw('CHAR_LENGTH(COALESCE(description, \'\')) >= ?', [(int) config('seo.artwork_gate.min_description_chars', 80)])
                    ->orWhereNotNull('medium')
                    ->orWhereNotNull('year')
                    ->orWhereNotNull('artist_id');
            })
            ->count();
    }

    private function indexableGalleryImageQuery()
    {
        return GalleryImage::query()
            ->whereHas('gallery', fn ($q) => $q->publiclyViewable())
            ->where(fn ($q) => $q->whereNotNull('title')->orWhereNotNull('original_name'));
    }
}
