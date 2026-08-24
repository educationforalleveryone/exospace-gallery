<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Models\Concerns\HasSeoProfile;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Artist;
use Illuminate\Support\Str;

/**
 * SEO metadata engine.
 *
 * Builds SeoData for every public entity type from REAL application data,
 * then layers seo_profile overrides on top (admin-controlled title /
 * description / canonical / robots / OG image). Templates receive a
 * finished SeoData and render it via <x-seo> — they never compose meta
 * tags, so future keyword work happens purely in data.
 *
 * All titles/descriptions are length-managed centrally (config limits).
 */
class SeoManager
{
    // ─────────────────────────────────────────────────────────────────────
    // Static / marketing pages
    // ─────────────────────────────────────────────────────────────────────

    public function forStaticPage(
        string $title,
        ?string $description = null,
        ?string $canonicalPath = null,
        ?string $robots = null,
    ): SeoData {
        return new SeoData(
            title: $this->applyTemplate('default', ['title' => $title]),
            description: $this->description($description),
            canonicalUrl: $canonicalPath ? CanonicalUrl::path($canonicalPath) : null,
            robots: $robots,
            ogTitle: $title,
            ogDescription: $this->description($description),
            ogImage: $this->defaultOgImage(),
        );
    }

    public function forHome(): SeoData
    {
        $template = (string) config('seo.templates.home', '{site} — Immersive 3D Art Galleries');

        return new SeoData(
            title: $this->interpolate($template, ['site' => $this->siteName()]),
            description: $this->description(),
            canonicalUrl: CanonicalUrl::path('/'),
            ogTitle: $this->siteName() . ' — Immersive 3D Art Galleries',
            ogDescription: $this->description(),
            ogImage: $this->defaultOgImage(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Galleries / exhibitions
    // ─────────────────────────────────────────────────────────────────────

    public function forGallery(Gallery $gallery): SeoData
    {
        $description = $this->galleryDescription($gallery);

        $seo = new SeoData(
            title: $this->applyTemplate('gallery', ['title' => $gallery->title ?: 'Untitled Exhibition']),
            description: $description,
            canonicalUrl: $gallery->public_url,
            ogTitle: $gallery->title ?: 'Untitled Exhibition',
            ogDescription: $description,
            ogImage: url("/gallery/{$gallery->slug}/og-image"),
            ogImageWidth: 1200,
            ogImageHeight: 630,
            ogImageAlt: 'Cover image for the 3D exhibition "' . ($gallery->title ?: 'Untitled Exhibition') . '"',
            ogType: 'website',
        );

        return $this->applyProfile($seo, $gallery);
    }

    /**
     * Gallery description with a REAL-DATA fallback: when the curator left
     * the description empty we generate a factual one from venue + artwork
     * count — never invented marketing copy.
     */
    private function galleryDescription(Gallery $gallery): string
    {
        if ($description = trim((string) $gallery->description)) {
            return $this->description($description);
        }

        $parts = [];
        $parts[] = 'A 3D virtual exhibition on Exospace';
        if ($gallery->venueTemplate?->name) {
            $parts[] = 'presented in the ' . $gallery->venueTemplate->name . ' venue';
        }
        $count = $gallery->images->count();
        if ($count > 0) {
            $parts[] = 'featuring ' . $count . ' ' . Str::plural('artwork', $count);
        }
        $artistNames = $gallery->images->filter(fn ($i) => $i->artist?->name)->pluck('artist.name')->unique()->take(3)->values();
        if ($artistNames->isNotEmpty()) {
            $parts[] = 'with works by ' . $artistNames->implode(', ');
        }

        return $this->description(implode(' ', $parts) . '. Walk through it in your browser.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Artists
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  int  $publicWorkCount  Number of works in publicly-viewable galleries
     * @param  int  $exhibitionCount  Number of distinct public exhibitions
     */
    public function forArtist(Artist $artist, int $publicWorkCount = 0, int $exhibitionCount = 0): SeoData
    {
        $description = $this->artistDescription($artist, $publicWorkCount, $exhibitionCount);

        $seo = new SeoData(
            title: $this->applyTemplate('artist', ['title' => $artist->name]),
            description: $description,
            canonicalUrl: CanonicalUrl::path('/artist/' . $artist->slug),
            ogTitle: $artist->name . ' — Artist on ' . $this->siteName(),
            ogDescription: $description,
            ogImage: url("/artist/{$artist->slug}/og-image"),
            ogImageWidth: 1200,
            ogImageHeight: 630,
            ogImageAlt: $artist->portrait_url
                ? ('Portrait of ' . $artist->name)
                : ('Artworks by ' . $artist->name),
            ogType: 'profile',
        );

        return $this->applyProfile($seo, $artist);
    }

    private function artistDescription(Artist $artist, int $workCount, int $exhibitionCount): string
    {
        if ($bio = trim((string) $artist->bio)) {
            return $this->description($bio);
        }

        // Factual fallback built from real data only.
        $parts = [];
        if ($artist->location) {
            $parts[] = $artist->location . '-based artist';
        } else {
            $parts[] = 'Artist';
        }
        if ($exhibitionCount > 0) {
            $parts[] = 'showing in ' . $exhibitionCount . ' ' . Str::plural('3D exhibition', $exhibitionCount);
        } elseif ($workCount > 0) {
            $parts[] = 'with ' . $workCount . ' ' . Str::plural('artwork', $workCount) . ' on display';
        }
        if ($parts === ['Artist']) {
            $parts[] = 'on ' . $this->siteName();
        }

        return $this->description(implode(' ', $parts) . '. Explore their works in immersive 3D galleries.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Artworks
    // ─────────────────────────────────────────────────────────────────────

    public function forArtwork(GalleryImage $artwork, Gallery $gallery): SeoData
    {
        $title = $artwork->title ?: $artwork->original_name ?: 'Untitled';
        $artistName = $artwork->artist?->name;

        $fullTitle = $artistName
            ? $this->applyTemplate('artwork', ['title' => $title, 'artist' => $artistName])
            : $this->applyTemplate('default', ['title' => $title]);

        $description = $this->artworkDescription($artwork, $gallery, $artistName);

        $seo = new SeoData(
            title: $fullTitle,
            description: $description,
            canonicalUrl: url("/gallery/{$gallery->slug}/artwork/{$artwork->id}"),
            ogTitle: $fullTitle,
            ogDescription: $description,
            ogImage: url("/gallery/{$gallery->slug}/og-image?artwork={$artwork->id}"),
            ogImageWidth: 1200,
            ogImageHeight: 630,
            ogImageAlt: $title . ($artistName ? ' by ' . $artistName : ''),
            ogType: 'article',
        );

        // Gallery carries the profile overrides for its content; artwork
        // pages inherit nothing to avoid overriding curator intent per-work.
        return $seo;
    }

    private function artworkDescription(GalleryImage $artwork, Gallery $gallery, ?string $artistName): string
    {
        $segments = [];

        if ($description = trim((string) $artwork->description)) {
            $segments[] = $description;
        }

        // Factual context: artist, exhibition, medium, year.
        $facts = [];
        if ($artistName) {
            $facts[] = 'By ' . $artistName . '.';
        }
        $facts[] = 'On view in "' . ($gallery->title ?: 'Untitled Exhibition') . '", a 3D virtual exhibition.';
        if ($artwork->medium) {
            $facts[] = $artwork->medium . '.';
        }
        if ($artwork->year) {
            $facts[] = 'Created ' . $artwork->year . '.';
        }
        if ($artwork->dimensions) {
            $facts[] = $artwork->dimensions . '.';
        }

        $segments[] = implode(' ', $facts);

        return $this->description(implode(' ', $segments));
    }

    // ─────────────────────────────────────────────────────────────────────
// Hubs (discover, artists, venues) — built in Iteration 2, signature ready
    // ─────────────────────────────────────────────────────────────────────

    public function forHub(
        string $templateKey,
        string $description,
        string $canonicalPath,
        ?SeoData $pagination = null,
    ): SeoData {
        $title = $this->applyTemplate($templateKey, []);

        $prev = $pagination?->prevUrl;
        $next = $pagination?->nextUrl;

        return new SeoData(
            title: $title,
            description: $this->description($description),
            canonicalUrl: CanonicalUrl::path($canonicalPath),
            ogTitle: $title,
            ogDescription: $this->description($description),
            ogImage: $this->defaultOgImage(),
            prevUrl: $prev,
            nextUrl: $next,
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Overrides / profiles
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Layer seo_profile overrides (when the model uses HasSeoProfile and
     * has a profile) on top of the generated SeoData.
     *
     * @param  HasSeoProfile&\Illuminate\Database\Eloquent\Model  $model
     */
    private function applyProfile(SeoData $seo, $model): SeoData
    {
        if (!method_exists($model, 'seoProfile')) {
            return $seo;
        }

        $profile = $model->seoProfile()->first();

        if (!$profile) {
            return $seo;
        }

        return $seo->with([
            'title' => $profile->title_override ?: null,
            'description' => $profile->description_override ?: null,
            'canonicalUrl' => $profile->canonical_override ?: null,
            'robots' => $profile->robots_directive ?: null,
            'ogImage' => $profile->og_image_path ? asset('storage/' . $profile->og_image_path) : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function siteName(): string
    {
        return (string) config('seo.site_name', 'Exospace');
    }

    private function defaultOgImage(): string
    {
        return asset((string) config('seo.og.default_image', 'img/og-default.png'));
    }

    /**
     * Apply a title template from config with placeholder interpolation.
     *
     * @param  array<string, string> $vars
     */
    private function applyTemplate(string $key, array $vars): string
    {
        $template = (string) config("seo.templates.{$key}", '{title}');
        $vars['site'] = $vars['site'] ?? $this->siteName();

        $title = $this->interpolate($template, $vars);

        return $this->limitTitle($title);
    }

    /**
     * @param  array<string, string> $vars
     */
    private function interpolate(string $template, array $vars): string
    {
        return preg_replace_callback(
            '/\{(\w+)\}/',
            fn ($m) => $vars[$m[1]] ?? $m[0],
            $template,
        ) ?? $template;
    }

    /** Length-managed description. Null input → platform default. */
    private function description(?string $text = null): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            $text = (string) config('seo.default_description');
        }

        $limit = (int) config('seo.limits.description', 155);

        return Str::limit($text, $limit);
    }

    private function limitTitle(string $title): string
    {
        return Str::limit(trim($title), (int) config('seo.limits.title', 60));
    }
}
