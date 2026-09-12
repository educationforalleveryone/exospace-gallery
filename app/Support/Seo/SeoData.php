<?php

declare(strict_types=1);

namespace App\Support\Seo;

final class SeoData
{
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

    public function robotsDirective(): string
    {
        return $this->robots ?? 'index,follow';
    }

    public function isIndexable(): bool
    {
        return !str_contains($this->robotsDirective(), 'noindex');
    }
}
