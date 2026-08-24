<?php

declare(strict_types=1);

namespace App\Support\Seo;

/**
 * One breadcrumb crumb: label + URL (URL null = current page / non-link).
 */
final class Breadcrumb
{
    public function __construct(
        public readonly string $label,
        public readonly ?string $url = null,
    ) {}

    /**
     * Build a breadcrumb trail from [label, url|null] pairs.
     * The last entry is treated as the current page (rendered without a link).
     *
     * @param  array<int, array{0: string, 1?: string|null}> $trail
     * @return array<int, self>
     */
    public static function trail(array $trail): array
    {
        $crumbs = [];
        $last = count($trail) - 1;
        foreach ($trail as $i => $entry) {
            $label = $entry[0];
            $url = $entry[1] ?? null;
            $crumbs[] = new self($label, $i === $last ? null : $url);
        }

        return $crumbs;
    }

    /**
     * BreadcrumbList JSON-LD graph for this trail.
     *
     * @param  array<int, self> $crumbs
     * @return array<string, mixed>
     */
    public static function toJsonLd(array $crumbs): array
    {
        $items = [];
        foreach ($crumbs as $i => $crumb) {
            $item = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb->label];
            if ($crumb->url) {
                $item['item'] = $crumb->url;
            }
            $items[] = $item;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /** HTML-escaped, length-managed label for rendering. */
    public function label(int $limit = 60): string
    {
        return \Illuminate\Support\Str::limit(trim($this->label), $limit);
    }
}
