<?php

declare(strict_types=1);

namespace App\Support\Seo;

/**
 * Immutable SEO metadata value object.
 *
 * Every indexable page produces exactly one SeoData instance, built either
 * by SeoManager (from an entity + its seo_profile overrides) or manually
 * for static pages. The <x-seo> Blade component renders it. Templates never
 * assemble meta tags themselves.
 *
 * DESIGN NOTE (keyword-readiness): the DTO carries no keyword field on
 * purpose — keyword targeting, when the strategy arrives, belongs in
 * seo_profiles.title_override / description_override and in seo_pages
 * content. The engine below is already override-driven, so applying a
 * future strategy requires zero template changes.
 */
final class SeoData
{
    /**
     * @param string|null $title           Page title (raw; template applied by SeoManager)
     * @param string|null $description     Meta description (already length-managed)
     * @param string|null $canonicalUrl    Absolute canonical URL (query-string managed)
     * @param string|null $robots          Robots directive, e.g. 'index,follow' or 'noindex,follow'. Null = omit tag (default index)
     * @param string|null $ogTitle         Open Graph title override
     * @param string|null $ogDescription   Open Graph description override
     * @param string|null $ogImage         Absolute OG image URL
     * @param int|null    $ogImageWidth
     * @param int|null    $ogImageHeight
     * @param string|null $ogImageAlt      Accessibility text for the OG image
     * @param string      $ogType          Open Graph type (website|article|profile|video.other)
     * @param string|null $twitterCard    Twitter card type; defaults from config
     * @param string|null $prevUrl         rel="prev" (paginated sequences)
     * @param string|null $nextUrl         rel="next" (paginated sequences)
     * @param string      $locale          og:locale, e.g. en_US
     * @param array<int, array<string, mixed>>|null $jsonLd Structured-data graphs to emit on this page
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $canonicalUrl = null,
        public readonly ?string $robots = null,
        public readonly ?string $ogTitle = null,
        public readonly ?string $ogDescription = null,
        public readonly ?string $ogImage = null,
        public readonly ?int $ogImageWidth = null,
        public readonly ?int $ogImageHeight = null,
        public readonly ?string $ogImageAlt = null,
        public readonly string $ogType = 'website',
        public readonly ?string $twitterCard = null,
        public readonly ?string $prevUrl = null,
        public readonly ?string $nextUrl = null,
        public readonly string $locale = 'en_US',
        public readonly ?array $jsonLd = null,
    ) {}

    /**
     * Fluent "with" copy helper — lets callers layer overrides (e.g. an
     * seo_profile replacing the auto-generated title) without mutating.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $props = [];
        foreach (get_object_vars($this) as $key => $value) {
            $props[$key] = array_key_exists($key, $changes) && $changes[$key] !== null
                ? $changes[$key]
                : $value;
        }

        return new self(...$props);
    }

    /** Effective robots directive with a sane default. */
    public function robotsDirective(): string
    {
        return $this->robots ?? 'index,follow';
    }

    public function isIndexable(): bool
    {
        return !str_contains($this->robotsDirective(), 'noindex');
    }
}
