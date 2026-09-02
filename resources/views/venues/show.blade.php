{{--
    Venue detail (SEO OS Iteration 2): venue template + the live public
    exhibitions built with it. Internal links flow both ways — every
    exhibition card links out, and exhibitions link back via breadcrumbs.
--}}
@extends('layouts.public')

@section('content')
    <div class="bg-gradient-to-br from-gray-900 via-brand-950/30 to-gray-900 border-b border-gray-800">
        <div class="max-w-page mx-auto px-4 py-14">
            {{-- Iteration 7 "Frontier" (roadmap P2.4): the venue page earns
                 its keep as a storefront — the hero still (the SAME image
                 the picker card shows, so still and page never disagree)
                 sits beside the pitch instead of a bare gradient. --}}
            <div class="grid lg:grid-cols-5 gap-10 lg:gap-14 items-center">
                <div class="lg:col-span-3">
                    <p class="text-brand-400 text-sm font-semibold tracking-widest uppercase mb-2">
                        @if($venue->category && isset(\App\Models\VenueTemplate::CATEGORIES[$venue->category]))
                            {{ \App\Models\VenueTemplate::CATEGORIES[$venue->category] }} Venue
                        @else
                            3D Venue
                        @endif
                    </p>
                    <h1 class="text-4xl md:text-5xl font-extrabold text-white mb-4">{{ $venue->name }}</h1>
                    @if($venue->description)
                        <p class="text-gray-400 text-lg leading-relaxed">{{ $venue->description }}</p>
                    @endif
                    <div class="flex flex-wrap gap-6 mt-6 text-sm text-gray-400">
                        <div><span class="text-xl font-bold text-white">{{ number_format($galleries->total()) }}</span> live {{ Str::plural('exhibition', $galleries->total()) }}</div>
                        @if($venue->capacity_max)
                            <div><span class="text-xl font-bold text-white">{{ $venue->capacity_max }}</span> artwork capacity</div>
                        @endif
                    </div>

                    {{-- Iteration 1 "The Rehearsal" (roadmap P1.1) → Iteration 7
                         "Frontier" (roadmap P2.4): the walkthrough is now EMBEDDED
                         — a click-to-load poster that swaps to the live sample
                         exhibition in place. Same no-signup contract (roadmap
                         DO NOT DO #10: previews are the funnel); the 3D runtime
                         boots only on click so the page itself stays fast for
                         crawlers and phones alike. No-JS visitors keep the
                         direct link via <noscript>. --}}
                    @featureFlag('venue_previews')
                    <div class="mt-8" data-venue-walkthrough>
                        <div data-walkthrough-poster
                             data-preview-url="{{ route('venues.preview', $venue->slug) }}"
                             role="button" tabindex="0"
                             aria-label="Load the live 3D walkthrough of the {{ $venue->name }} venue"
                             class="relative overflow-hidden rounded-2xl border border-white/10 cursor-pointer group shadow-xl shadow-black/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                            @if($venue->thumbnail_url)
                                <img src="{{ $venue->thumbnail_url }}"
                                     alt="{{ $venue->name }} venue — hero still"
                                     loading="lazy" decoding="async"
                                     class="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.03]">
                                <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/30 to-black/10"></div>
                            @else
                                <div class="absolute inset-0 bg-gradient-to-br from-brand-900/50 via-gray-900 to-black"></div>
                            @endif
                            <div class="relative aspect-video flex flex-col items-center justify-center gap-4 p-6 text-center">
                                <span class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-white/10 border border-white/25 backdrop-blur-md group-hover:bg-brand-500/30 group-hover:border-brand-400/50 group-hover:scale-105 transition-all">
                                    <svg class="w-6 h-6 text-white ml-1" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                </span>
                                <span class="text-white font-semibold">Walk through this venue — live 3D</span>
                                <span class="text-gray-400 text-xs">Sample exhibition · demonstration artworks · no signup required</span>
                            </div>
                        </div>
                        <noscript>
                            <a href="{{ route('venues.preview', $venue->slug) }}"
                               class="inline-flex items-center gap-2.5 px-7 py-3.5 mt-3 rounded-full bg-gradient-to-r from-blue-500 to-purple-500 text-white font-semibold text-sm shadow-lg shadow-purple-500/25">
                                Walk through this venue
                            </a>
                        </noscript>
                        <p class="text-gray-500 text-xs mt-2.5">
                            A live 3D sample exhibition with demonstration artworks — no signup required.
                        </p>
                    </div>
                    @endfeatureFlag
                </div>

                <div class="lg:col-span-2">
                    @if($venue->thumbnail_url)
                        <figure class="relative overflow-hidden rounded-2xl border border-white/10 shadow-2xl shadow-black/40">
                            <img src="{{ $venue->thumbnail_url }}"
                                 alt="{{ $venue->name }} — venue hero still"
                                 loading="eager" decoding="async"
                                 class="w-full aspect-[4/3] object-cover">
                            <figcaption class="absolute bottom-0 inset-x-0 bg-gradient-to-t from-black/80 to-transparent px-4 py-3 text-xs text-gray-300">
                                The {{ $venue->name }} venue — rendered by the same 3D engine your exhibition uses.
                            </figcaption>
                        </figure>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-page mx-auto px-4 py-10">
        <div class="mb-6">
            <x-breadcrumbs :crumbs="$breadcrumbs" />
        </div>

        <h2 class="text-2xl font-bold text-white mb-6">Live Exhibitions in {{ $venue->name }}</h2>

        @if($galleries->count() > 0)
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                @foreach($galleries as $gallery)
                    <a href="{{ $gallery->public_url }}" class="card card-interactive card-lift group overflow-hidden">
                        <div class="aspect-[4/3] bg-gray-900 overflow-hidden relative">
                            @if($gallery->coverImage)
                                <img src="{{ $gallery->coverImage->public_url }}"
                                     srcset="{{ $gallery->coverImage->srcset }}"
                                     sizes="(max-width: 768px) 100vw, (max-width: 1200px) 50vw, 33vw"
                                     alt="{{ $gallery->title }}"
                                     loading="lazy" decoding="async"
                                     class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                            @else
                                <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-brand-900/30 to-gray-900">
                                    <svg class="w-16 h-16 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                </div>
                            @endif
                        </div>
                        <div class="p-4">
                            <h3 class="text-gray-100 font-semibold leading-tight mb-1 line-clamp-2 group-hover:text-brand-300 transition-colors">{{ $gallery->title }}</h3>
                            <p class="text-gray-500 text-xs flex items-center gap-3">
                                <span>{{ $gallery->images->count() }} {{ Str::plural('artwork', $gallery->images->count()) }}</span>
                                <span>{{ number_format($gallery->view_count) }} views</span>
                            </p>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-10">
                {{ $galleries->links() }}
            </div>
        @else
            <div class="text-center py-16">
                <p class="text-gray-400">No live exhibitions in this venue right now.</p>
            </div>
        @endif

        <nav class="mt-12 pt-8 border-t border-gray-800 flex flex-wrap gap-4 text-sm" aria-label="Discover more">
            <a href="{{ route('venues.index') }}" class="text-brand-400 hover:text-brand-300 transition">All venue templates</a>
            <a href="{{ route('discover') }}" class="text-brand-400 hover:text-brand-300 transition">Browse 3D exhibitions</a>
            <a href="{{ route('register') }}" class="text-brand-400 hover:text-brand-300 transition">Create your own exhibition</a>
        </nav>
    </div>

    {{-- Iteration 7 "Frontier" (P2.4): click-to-load embed wiring. The poster
         swaps to the preview iframe (same-origin, no-signup, rate-limited at
         the route). Keyboard parity: Enter/Space activate the poster. The
         iframe only ever exists AFTER an explicit user action, so the page
         itself stays light for crawlers and mobile. --}}
    @featureFlag('venue_previews')
    <script>
        (function () {
            var wrap = document.querySelector('[data-venue-walkthrough]');
            if (!wrap) return;
            var poster = wrap.querySelector('[data-walkthrough-poster]');
            if (!poster) return;

            function load() {
                if (wrap.querySelector('iframe')) return;
                var iframe = document.createElement('iframe');
                iframe.src = poster.getAttribute('data-preview-url');
                iframe.title = 'Live 3D walkthrough of the {{ $venue->name }} venue';
                iframe.setAttribute('allow', 'fullscreen; xr-spatial-tracking');
                iframe.loading = 'lazy';
                iframe.style.cssText = 'width:100%;aspect-ratio:16/9;border:0;border-radius:1rem;display:block;background:#000;';
                poster.replaceWith(iframe);
                iframe.focus();
            }

            poster.addEventListener('click', load);
            poster.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                    e.preventDefault();
                    load();
                }
            });
        })();
    </script>
    @endfeatureFlag
@endsection
