{{--
    SEO head component v2 (SEO Operating System, Iteration 1).

    Renders the full meta layer from a SeoData value object when given one:

        @php $seo = app(\App\Support\Seo\SeoManager::class)->forGallery($gallery); @endphp
        <x-seo :seo="$seo" />

    Legacy string-props mode still works (public pages pass plain strings):

        <x-seo title="..." description="..." canonical-url="..." />

    New capabilities in v2 (all optional):
      - robots directive emission (noindex handling)
      - og:image dimensions + type + alt
      - og:locale
      - rel=prev/next for paginated sequences
      - JSON-LD graphs carried on the SeoData object

    Canonical policy: when no canonical is provided the component does NOT
    fall back to url()->current() anymore (that produced query-string
    self-canonicals — audit C4). It falls back to CanonicalUrl::clean() of
    the current URL so tracking params are always stripped, and paginated
    listings pass an explicit canonical.
--}}
@props([
    'seo' => null,
    'title' => null,
    'description' => null,
    'canonicalUrl' => null,
    'robots' => null,
    'ogTitle' => null,
    'ogDescription' => null,
    'ogImage' => null,
    'ogImageWidth' => null,
    'ogImageHeight' => null,
    'ogImageAlt' => null,
    'ogType' => null,
    'twitterCard' => null,
    'prevUrl' => null,
    'nextUrl' => null,
    'locale' => null,
    'jsonLd' => null,
])

@php
    use App\Support\Seo\SeoData;
    use App\Support\Seo\CanonicalUrl;

    $data = $seo ?? null;

    $siteName = config('seo.site_name', config('app.name', 'Exospace'));

    if ($data instanceof SeoData) {
        $title       = $data->title ?? ($siteName . ' — Immersive 3D Art Galleries');
        $description = $data->description ?? config('seo.default_description');
        $canonical   = $data->canonicalUrl ?: CanonicalUrl::clean(url()->current());
        $robots      = $data->robots;
        $ogTitle     = $data->ogTitle ?? $title;
        $ogDesc      = $data->ogDescription ?? $description;
        $ogImage     = $data->ogImage ?? asset((string) config('seo.og.default_image', 'img/og-default.png'));
        $ogImageW    = $data->ogImageWidth ?? config('seo.og.default_image_width', 1200);
        $ogImageH    = $data->ogImageHeight ?? config('seo.og.default_image_height', 630);
        $ogImageAlt  = $data->ogImageAlt;
        $ogType      = $data->ogType;
        $twitterCard = $data->twitterCard ?? config('seo.og.twitter_card', 'summary_large_image');
        $prevUrl     = $data->prevUrl;
        $nextUrl     = $data->nextUrl;
        $locale      = $data->locale;
        $jsonLd      = $data->jsonLd;
    } else {
        // Legacy string-props mode.
        //
        // ITERATION-1 FIX: `?:` on an UNDEFINED variable raises a warning
        // (promoted to an exception in tests) — when callers render the
        // component without an explicit canonical, every page 500'd in
        // this mode. isset() guards first, then the empty-string fallback.
        $title       = $title ?? $siteName . ' — Immersive 3D Art Galleries';
        $description = $description ?? config('seo.default_description');
        $canonical   = isset($canonicalUrl) && $canonicalUrl !== ''
            ? $canonicalUrl
            : CanonicalUrl::clean(url()->current());
        $robots      = $robots ?? null;
        $ogTitle     = $ogTitle ?? $title;
        $ogDesc      = $ogDescription ?? $description;
        $ogImage     = $ogImage ?? asset((string) config('seo.og.default_image', 'img/og-default.png'));
        $ogImageW    = $ogImageWidth ?? config('seo.og.default_image_width', 1200);
        $ogImageH    = $ogImageHeight ?? config('seo.og.default_image_height', 630);
        $ogImageAlt  = $ogImageAlt ?? null;
        $ogType      = $ogType ?? 'website';
        $twitterCard = $twitterCard ?? config('seo.og.twitter_card', 'summary_large_image');
        $prevUrl     = $prevUrl ?? null;
        $nextUrl     = $nextUrl ?? null;
        $locale      = $locale ?? config('seo.og.locale', 'en_US');
        $jsonLd      = $jsonLd ?? null;
    }
@endphp

<title>{{ $title }}</title>
<meta name="description" content="{{ $description }}">

@if($robots)
<meta name="robots" content="{{ $robots }}">
@endif

<link rel="canonical" href="{{ $canonical }}">

@if($prevUrl)
<link rel="prev" href="{{ $prevUrl }}">
@endif
@if($nextUrl)
<link rel="next" href="{{ $nextUrl }}">
@endif

{{-- Open Graph (Facebook, LinkedIn, Slack) --}}
<meta property="og:type" content="{{ $ogType }}">
<meta property="og:title" content="{{ $ogTitle }}">
<meta property="og:description" content="{{ $ogDesc }}">
<meta property="og:image" content="{{ $ogImage }}">
<meta property="og:image:width" content="{{ (int) $ogImageW }}">
<meta property="og:image:height" content="{{ (int) $ogImageH }}">
@if($ogImageAlt)
<meta property="og:image:alt" content="{{ $ogImageAlt }}">
@endif
<meta property="og:image:type" content="{{ config('seo.og.image_type', 'image/png') }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:locale" content="{{ $locale }}">

{{-- Twitter Card --}}
<meta name="twitter:card" content="{{ $twitterCard }}">
<meta name="twitter:title" content="{{ $ogTitle }}">
<meta name="twitter:description" content="{{ $ogDesc }}">
<meta name="twitter:image" content="{{ $ogImage }}">
@if($ogImageAlt)
<meta name="twitter:image:alt" content="{{ $ogImageAlt }}">
@endif

{{-- Structured data carried by the SeoData object --}}
@if(!empty($jsonLd))
@foreach($jsonLd as $graph)
<script type="application/ld+json">
{{-- ITERATION-1 FIX: keep graphs compact (the standalone x-json-ld --}}
{{-- component is pretty-printed; the seo component's graph blocks are --}}
{{-- compact to minimize inline payload on every public page). --}}
{!! json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endforeach
@endif
