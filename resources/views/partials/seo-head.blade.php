{{-- SEO head partial (ITERATION-1 FIX): renders the complete meta layer at
     the exact position in the layout where @yield section injection is
     active — component attributes cannot receive @yield output. --}}
@php
    // Resolve section contents BEFORE the component call — inside this
    // partial (included from the layout), @yield works positionally, but
    // attributes need resolved values.
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
