@php
@endphp

@php
$schema = $schema ?? null;

if (! $schema && isset($type)) {
    $appUrl = config('app.url');
    $appName = config('app.name', 'Exospace Gallery');

    $schema = match ($type) {
        'organization' => [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $appName,
            'url' => $appUrl,
            'logo' => $appUrl . '/android-chrome-192x192.png',
            'description' => 'Create museum-quality 3D art exhibitions in minutes. Upload your images, pick a venue, share a link.',
            'sameAs' => [
            ],
        ],
        'product' => (function () use ($appUrl, $appName, $product) {
            // $product is expected to be an array: ['name' => 'Pro', 'price' => 29.00, 'currency' => 'USD', 'description' => '...']
            return [
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $appName . ' ' . ($product['name'] ?? 'Plan'),
                'description' => $product['description'] ?? 'Exospace Gallery plan',
                'brand' => ['@type' => 'Brand', 'name' => $appName],
                'offers' => [
                    '@type' => 'Offer',
                    'price' => number_format((float) ($product['price'] ?? 0), 2, '.', ''),
                    'priceCurrency' => $product['currency'] ?? 'USD',
                    'availability' => 'https://schema.org/InStock',
                    'url' => $appUrl . '/pricing',
                    'category' => 'SoftwareApplication',
                ],
            ];
        })(),
        'faq-page' => (function () use ($faqs) {
            // $faqs is expected to be an array of ['question' => '...', 'answer' => '...']
            $entities = array_map(fn ($qa) => [
                '@type' => 'Question',
                'name' => $qa['question'] ?? '',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $qa['answer'] ?? '',
                ],
            ], $faqs ?? []);

            return [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => $entities,
            ];
        })(),
        'item-list' => (function () use ($items, $appUrl) {
            // $items is expected to be a Collection of Gallery models with slug + title.
            $list = [];
            foreach (($items ?? []) as $i => $gallery) {
                $list[] = [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => $gallery->title ?? '',
                    'url' => $appUrl . '/gallery/' . $gallery->slug,
                ];
            }
            return [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => 'Featured 3D Exhibitions on Exospace',
                'url' => $appUrl . '/discover',
                'itemListElement' => $list,
            ];
        })(),
        default => null,
    };
}
@endphp

@if($schema)
<script type="application/ld+json">
{{-- Script-safe JSON: subject-controlled values must not be able to --}}
{{-- terminate the script element from inside a JSON string. --}}
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}
</script>
@endif
