@php
    $seoTitle = \Illuminate\Support\Facades\View::yieldContent('title');
    $seoTitle = trim(preg_replace('/\s+/', ' ', strip_tags((string) $seoTitle)));
    $seoTitle = $seoTitle !== '' ? $seoTitle : config('app.name', 'Exospace');

    $seoDescription = \Illuminate\Support\Facades\View::yieldContent('description');
    $seoDescription = trim(preg_replace('/\s+/', ' ', strip_tags((string) $seoDescription)));
    $seoDescription = $seoDescription !== '' ? $seoDescription : config('seo.default_description');

    $seoCanonical = trim((string) \Illuminate\Support\Facades\View::yieldContent('canonical'));
@endphp
<x-seo
    :seo="$seoData ?? null"
    :title="$seoTitle"
    :description="$seoDescription"
    :canonical-url="$seoCanonical !== '' ? $seoCanonical : null"
/>
