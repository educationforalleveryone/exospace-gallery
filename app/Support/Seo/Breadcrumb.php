<?php

declare(strict_types=1);

namespace App\Support\Seo;

final class Breadcrumb
{
    public function __construct(
        public readonly string $label,
        public readonly ?string $url = null,
    ) {}

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

    public function label(int $limit = 60): string
    {
        return \Illuminate\Support\Str::limit(trim($this->label), $limit);
    }
}
