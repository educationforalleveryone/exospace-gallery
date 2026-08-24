{{-- SEO OS (Iteration 2): metadata now comes from the controller via $seoData
     (canonical policy for pagination/filters, prev/next, robots for filtered
     views). The old @section title/description lines are gone — single
     source of truth is DiscoverController. --}}
@extends('layouts.public')

@section('content')

<!-- H-2 FIX (Iter-012): Discover page now extends layouts.public for proper SEO meta, public nav, footer, cookie banner, skip-link. Was previously using layouts.guest (auth sidebar nav). -->
    <div class="bg-gradient-to-br from-gray-900 via-purple-950/30 to-gray-900 border-b border-gray-800">
            <div class="max-w-7xl mx-auto px-4 py-16">
                <div class="text-center">
                    <p class="text-purple-400 text-sm font-semibold tracking-widest uppercase mb-3">Discover</p>
                    <h1 class="text-4xl md:text-5xl font-extrabold text-white mb-4">Featured 3D Exhibitions</h1>
                    <p class="text-gray-400 text-lg max-w-2xl mx-auto">Walk through virtual galleries curated by artists, photographers, and institutions from around the world.</p>
                </div>
            </div>
        </div>

    {{-- CONV-7 FIX: Loading overlay shown when user clicks a pagination link,
         filter dropdown, or sort link. Without this, the page appears frozen
         during the navigation delay (especially on slow connections), and
         users often click pagination links multiple times thinking the first
         click didn't register. The overlay uses role="status" + aria-live="polite"
         so screen readers announce "Loading exhibitions" when navigation starts. --}}
    <div id="discover-loading-overlay"
         role="status"
         aria-live="polite"
         aria-label="Loading exhibitions"
         style="display:none; position:fixed; inset:0; background:rgba(17,24,39,0.7); backdrop-filter:blur(4px); z-index:50; align-items:center; justify-content:center;">
        <div style="text-align:center;">
            <div style="display:inline-block; width:40px; height:40px; border:3px solid rgba(167,139,250,0.2); border-top-color:#a78bfa; border-radius:50%; animation: discover-spin 0.8s linear infinite;"></div>
            <p style="margin-top:12px; color:#a78bfa; font-size:14px; font-weight:500;">Loading exhibitions&hellip;</p>
        </div>
    </div>
    <style>
        @keyframes discover-spin { to { transform: rotate(360deg); } }
    </style>
    <script nonce="@nonce">
        // Show the overlay whenever an in-page navigation happens (pagination
        // link, sort link, or venue filter form submit). Hide it on page load
        // (which only fires once per full page load — the next navigation
        // will show it again).
        (function() {
            function showLoading() {
                var el = document.getElementById('discover-loading-overlay');
                if (el) el.style.display = 'flex';
            }
            document.addEventListener('DOMContentLoaded', function() {
                var navLinks = document.querySelectorAll('a[href^="?"], a[href*="page="], a[href*="sort="], a[href*="venue="]');
                navLinks.forEach(function(link) {
                    link.addEventListener('click', showLoading);
                });
                var venueForm = document.querySelector('form[method="GET"]');
                if (venueForm) {
                    venueForm.addEventListener('submit', showLoading);
                }
            });
            window.addEventListener('pageshow', function() {
                var el = document.getElementById('discover-loading-overlay');
                if (el) el.style.display = 'none';
            });
        })();

        // CSP-safe delegated change handler: submit the parent form
        window.submitForm = function(el, e) {
            if (el.form) el.form.submit();
        };
    </script>

    <div class="max-w-7xl mx-auto px-4 py-10">
        {{-- Filters bar --}}
        <div class="flex flex-wrap items-center justify-between gap-4 mb-8">
            <div class="flex items-center gap-3">
                <form method="GET" class="flex items-center gap-2">
                    <select name="venue" data-change="submitForm" class="rounded-lg bg-gray-800 border-gray-700 text-gray-100 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500">
                        <option value="">All venues</option>
                        @foreach($venues as $id => $name)
                            <option value="{{ $id }}" {{ (string)$venueId === (string)$id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" name="sort" value="{{ $sort }}">
                </form>
            </div>

            <div class="flex items-center gap-1 text-sm">
                <span class="text-gray-500 mr-2">Sort:</span>
                <a href="?sort=featured{{ $venueId ? '&venue='.$venueId : '' }}" class="px-3 py-1.5 rounded-lg {{ $sort === 'featured' ? 'bg-purple-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }} transition">Featured</a>
                <a href="?sort=views{{ $venueId ? '&venue='.$venueId : '' }}" class="px-3 py-1.5 rounded-lg {{ $sort === 'views' ? 'bg-purple-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }} transition">Most viewed</a>
                <a href="?sort=newest{{ $venueId ? '&venue='.$venueId : '' }}" class="px-3 py-1.5 rounded-lg {{ $sort === 'newest' ? 'bg-purple-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }} transition">Newest</a>
                <a href="?sort=updated{{ $venueId ? '&venue='.$venueId : '' }}" class="px-3 py-1.5 rounded-lg {{ $sort === 'updated' ? 'bg-purple-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }} transition">Recently updated</a>
            </div>
        </div>

        {{-- Gallery grid --}}
        @if($galleries->count() > 0)
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                @foreach($galleries as $gallery)
                    <a href="{{ $gallery->public_url }}" class="group bg-gray-800/60 border border-gray-700/50 rounded-xl overflow-hidden hover:border-purple-500 hover:-translate-y-1 hover:shadow-xl hover:shadow-purple-900/20 transition-all duration-300">
                        {{-- Cover --}}
                        <div class="aspect-[4/3] bg-gray-900 overflow-hidden relative">
                            @if($gallery->coverImage)
                                <img src="{{ $gallery->coverImage->public_url }}"
                                     srcset="{{ $gallery->coverImage->srcset }}"
                                     sizes="(max-width: 768px) 100vw, (max-width: 1200px) 50vw, 33vw"
                                     alt="{{ $gallery->title }}"
                                     loading="lazy" decoding="async"
                                     class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                            @else
                                <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-purple-900/30 to-gray-900">
                                    <svg class="w-16 h-16 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                </div>
                            @endif
                            {{-- Venue badge --}}
                            @if($gallery->venueTemplate)
                                <span class="absolute top-2 left-2 px-2 py-0.5 rounded-full text-xs font-medium bg-black/70 backdrop-blur-sm text-gray-200 border border-white/10">{{ $gallery->venueTemplate->name }}</span>
                            @endif
                        </div>

                        {{-- Body --}}
                        <div class="p-4">
                            <h3 class="text-gray-100 font-semibold leading-tight mb-1 line-clamp-2 group-hover:text-purple-300 transition-colors">{{ $gallery->title }}</h3>
                            @if($gallery->description)
                                <p class="text-gray-400 text-xs line-clamp-2 mb-3">{{ Str::limit($gallery->description, 120) }}</p>
                            @endif
                            <div class="flex items-center justify-between text-xs text-gray-500">
                                <span>{{ $gallery->images()->count() }} artworks</span>
                                <span>{{ number_format($gallery->view_count) }} views</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-8">
                {{ $galleries->withQueryString()->links() }}
            </div>
        @else
            <div class="text-center py-24">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <p class="text-gray-400 mb-2">No public exhibitions found.</p>
                <p class="text-gray-600 text-sm">Check back soon — new galleries open every week.</p>
            </div>
        @endif
    </div>

{{-- I-2 FIX (Iter-013): ItemList JSON-LD for the discover page.
    Renders the top 10 featured galleries as a structured list in Google
    search results. Improves discoverability and CTR from SERPs. --}}
@if($galleries->isNotEmpty())
    <x-json-ld type="item-list" :items="$galleries->take(10)" />
@endif

@endsection
