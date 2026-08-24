{{--
    SEO page shell (Iteration 5): landing + editorial pages render here.
    Block partials are a CLOSED allow-list validated by SeoPageRenderer —
    no raw HTML passthrough, CSP-safe.
--}}
@extends('layouts.public')

@section('content')
    <div class="max-w-5xl mx-auto px-4 pt-6">
        <x-breadcrumbs :crumbs="$breadcrumbs" />
    </div>

    @if($isPreview)
        <div class="max-w-5xl mx-auto px-4 pt-4">
            <div class="p-3 rounded-lg bg-yellow-900/30 border border-yellow-700/50 text-yellow-200 text-sm flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-.01-9a9 9 0 100 18 9 9 0 000-18z"/></svg>
                <span><strong>Preview</strong> — this page is not published or not indexable. It carries <code>noindex</code> and never appears in sitemaps.</span>
            </div>
        </div>
    @endif

    <main id="seo-page-content">
        {{-- Pre-rendered block partials (safe allow-list) --}}
        {!! $content !!}
    </main>
@endsection
