{{--
    SEO head component (Task H13 / audit H38, H39).

    Centralizes meta tags for title, description, canonical URL, and
    Open Graph / Twitter Card tags. Previously these existed ONLY on
    gallery/view.blade.php — the homepage, pricing, discover, artist
    profiles, and all legal pages had no description, no canonical,
    and broken social cards.

    Usage:
        <x-seo
            title="Exospace — Immersive 3D Art Galleries"
            description="Create museum-quality 3D exhibitions in minutes..."
            :og-image="asset('img/og-default.png')"
            canonical-url="{{ url()->current() }}"
        />

    All props are optional — defaults are provided.
--}}
@php
    $title = $title ?? config('app.name', 'Exospace') . ' — Immersive 3D Art Galleries';
    $description = $description ?? 'Create museum-quality 3D art exhibitions in minutes. Upload your images, pick a venue, share a link. Free to start.';
    $ogImage = $ogImage ?? asset('img/og-default.png');
    $canonicalUrl = $canonicalUrl ?? url()->current();
    $ogType = $ogType ?? 'website';
    $twitterCard = $twitterCard ?? 'summary_large_image';
@endphp

<title>{{ $title }}</title>
<meta name="description" content="{{ $description }}">
<link rel="canonical" href="{{ $canonicalUrl }}">

{{-- Open Graph (Facebook, LinkedIn, Slack) --}}
<meta property="og:type" content="{{ $ogType }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:image" content="{{ $ogImage }}">
<meta property="og:url" content="{{ $canonicalUrl }}">
<meta property="og:site_name" content="{{ config('app.name', 'Exospace') }}">

{{-- Twitter Card --}}
<meta name="twitter:card" content="{{ $twitterCard }}">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $ogImage }}">
