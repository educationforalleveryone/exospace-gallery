<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\VenueTemplate;
use App\Models\SeoPage;
use App\Support\Seo\Breadcrumb;
use App\Support\Seo\SeoData;
use Illuminate\Support\Collection;

class SeoPageRenderer
{
    private const BLOCK_TYPES = [
        'hero', 'text', 'features', 'faq', 'cta',
        'exhibitions', 'artists', 'venues',
    ];

    public function renderBlocks(SeoPage $page): string
    {
        $html = '';
        foreach ($this->validatedBlocks($page) as $block) {
            $html .= view('seo.pages.blocks.' . $block['type'], [
                'data'    => $block['data'],
                'page'    => $page,
                'context' => $this->blockContext($block['type']),
            ])->render();
        }

        return $html;
    }

    public function seoFor(SeoPage $page, bool $isPreview = false): SeoData
    {
        $indexable = $page->isIndexable() && !$isPreview;

        $title = $page->effectiveTitle();
        $description = $page->meta_description
            ?: \Illuminate\Support\Str::limit($this->firstTextBlock($page) ?: $page->title . ' — ' . config('seo.site_name', 'Exospace'), 155);

        $seo = new SeoData(
            title: \Illuminate\Support\Str::limit($title, 60),
            description: $description,
            canonicalUrl: $page->canonical_override ?: $page->public_url,
            robots: $indexable ? null : 'noindex,follow',
            ogTitle: $title,
            ogDescription: $description,
            ogImage: $page->og_image_path ? asset('storage/' . $page->og_image_path) : asset((string) config('seo.og.default_image', 'img/og-default.png')),
            ogType: $page->type === 'editorial' ? 'article' : 'website',
        );

        $graphs = [];
        if ($page->type === 'editorial') {
            $graphs[] = [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $page->title,
                'url' => $page->public_url,
                'author' => ['@type' => 'Organization', 'name' => config('seo.site_name', 'Exospace')],
                'publisher' => ['@type' => 'Organization', 'name' => config('seo.site_name', 'Exospace')],
            ];
        }
        // FAQPage schema only from REAL authored FAQ blocks.
        $faqs = $this->faqItems($page);
        if ($faqs !== []) {
            $graphs[] = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn ($f) => [
                    '@type' => 'Question',
                    'name' => $f['question'] ?? '',
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer'] ?? ''],
                ], $faqs),
            ];
        }
        if ($graphs !== []) {
            $seo = $seo->with(['jsonLd' => $graphs]);
        }

        return $seo;
    }

    public function breadcrumbsFor(SeoPage $page): array
    {
        $trail = [['Home', url('/')]];

        if ($page->type === 'editorial') {
            // The editorial prefix has no hub route — keep the crumb visible
            // but unlinked so it never points at a missing page (also in the
            // BreadcrumbList structured data).
            $trail[] = [ucfirst((string) config('seo.pages.editorial_prefix', 'resources'))];
        }
        $trail[] = [$page->title];

        return Breadcrumb::trail($trail);
    }

    public function validatedBlocks(SeoPage $page): array
    {
        $blocks = $page->blocks;

        if (!is_array($blocks)) {
            return [];
        }

        $valid = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;
            $data = $block['data'] ?? [];

            if (is_string($type) && in_array($type, self::BLOCK_TYPES, true) && is_array($data)) {
                $valid[] = ['type' => $type, 'data' => $data];
            }
        }

        return $valid;
    }

    private function blockContext(string $type): array
    {
        return match ($type) {
            'exhibitions' => ['items' => $this->liveExhibitions()],
            'artists' => ['items' => $this->liveArtists()],
            'venues' => ['items' => $this->liveVenues()],
            default => [],
        };
    }

    private function liveExhibitions(): Collection
    {
        return \Illuminate\Support\Facades\Cache::remember('seo:page:exhibitions', 900, fn () =>
            Gallery::publiclyViewable()
                ->with(['coverImage', 'venueTemplate'])
                ->has('images', '>=', 1)
                ->whereDoesntHave('user', fn ($q) => $q->whereNotNull('banned_at'))
                ->orderByDesc('is_featured')
                ->orderByDesc('view_count')
                ->take(6)
                ->get());
    }

    private function liveArtists(): Collection
    {
        return \Illuminate\Support\Facades\Cache::remember('seo:page:artists', 900, fn () =>
            Artist::query()
                ->whereHas('images.gallery', fn ($q) => $q->publiclyViewable())
                ->withCount(['images as public_works_count' => fn ($q) => $q->whereHas('gallery', fn ($g) => $g->publiclyViewable())])
                ->orderByDesc('public_works_count')
                ->take(6)
                ->get());
    }

    private function liveVenues(): Collection
    {
        return \Illuminate\Support\Facades\Cache::remember('seo:page:venues', 900, fn () =>
            VenueTemplate::active()
                ->published()
                ->whereHas('galleries', fn ($q) => $q->publiclyViewable()->has('images', '>=', 1))
                ->withCount(['galleries as public_galleries_count' => fn ($q) => $q->publiclyViewable()->has('images', '>=', 1)])
                ->orderByDesc('public_galleries_count')
                ->take(6)
                ->get());
    }

    private function firstTextBlock(SeoPage $page): ?string
    {
        foreach ($this->validatedBlocks($page) as $block) {
            if (in_array($block['type'], ['text', 'hero'], true)) {
                $body = $block['data']['body'] ?? $block['data']['subtitle'] ?? null;
                if (is_string($body) && trim($body) !== '') {
                    return $body;
                }
            }
        }

        return null;
    }

    private function faqItems(SeoPage $page): array
    {
        foreach ($this->validatedBlocks($page) as $block) {
            if ($block['type'] === 'faq') {
                $items = $block['data']['items'] ?? [];
                if (is_array($items)) {
                    return array_values(array_filter($items, fn ($i) =>
                        is_array($i) && !empty($i['question']) && !empty($i['answer'])));
                }
            }
        }

        return [];
    }
}
