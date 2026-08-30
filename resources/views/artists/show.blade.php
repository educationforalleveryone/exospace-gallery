{{--
    Public artist profile (SEO OS Iteration 2 — fixes audit C1).

    Previously rendered inside <x-guest-layout>, which emitted
    noindex,nofollow — artist profiles were invisible to search engines
    and had no unique title/description/canonical.

    Now: public layout + controller-built SeoData (unique title with
    fallback description, canonical, Person JSON-LD, og:image via the
    artist OG image endpoint, breadcrumbs Home → Artists → name).
--}}
@extends('layouts.public')

@section('content')
    {{-- Breadcrumbs --}}
    <div class="max-w-5xl mx-auto px-4 pt-6">
        <x-breadcrumbs :crumbs="$breadcrumbs" />
    </div>

    {{-- Artist header --}}
    <header class="bg-gradient-to-br from-gray-900 via-brand-950/30 to-gray-900 border-b border-gray-800">
        <div class="max-w-5xl mx-auto px-4 py-12">
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-6">
                <div class="w-28 h-28 rounded-full overflow-hidden bg-gradient-to-br from-brand-900/40 to-gray-900 flex items-center justify-center flex-shrink-0 border-2 border-brand-700/30">
                    @if($artist->portrait_url)
                        <img src="{{ $artist->portrait_url }}" alt="Portrait of {{ $artist->name }}" class="w-full h-full object-cover" width="112" height="112">
                    @else
                        <span class="text-4xl font-bold text-gray-600">{{ $artist->initials }}</span>
                    @endif
                </div>
                <div class="flex-1">
                    <p class="text-brand-400 text-xs font-semibold tracking-widest uppercase mb-1">Artist Profile</p>
                    <h1 class="text-3xl md:text-4xl font-extrabold text-white">{{ $artist->name }}</h1>
                    @if($artist->location)
                        <p class="text-gray-400 mt-1 flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            {{ $artist->location }}
                        </p>
                    @endif
                    @if($artist->bio)
                        <p class="text-gray-300 mt-4 leading-relaxed whitespace-pre-line max-w-2xl">{{ $artist->bio }}</p>
                    @endif
                    <div class="flex flex-wrap gap-2 mt-4">
                        @if($artist->website)
                            <a href="{{ $artist->website }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-800/80 border border-gray-700 hover:border-brand-500 text-gray-200 text-sm transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                                Website
                            </a>
                        @endif
                        @if($artist->instagram)
                            <a href="{{ $artist->instagram_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-800/80 border border-gray-700 hover:border-brand-500 text-gray-200 text-sm transition">
                                @{{ $artist->instagram }}
                            </a>
                        @endif
                        @if($artist->twitter)
                            <a href="{{ $artist->twitter_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-800/80 border border-gray-700 hover:border-brand-500 text-gray-200 text-sm transition">
                                @{{ $artist->twitter }}
                            </a>
                        @endif
                        @if($artist->email)
                            <a href="mailto:{{ $artist->email }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-800/80 border border-gray-700 hover:border-brand-500 text-gray-200 text-sm transition">
                                Contact
                            </a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Factual stats row --}}
            <div class="flex gap-8 mt-8 text-sm text-gray-400">
                <div>
                    <span class="text-xl font-bold text-white">{{ number_format($workCount) }}</span> artworks
                </div>
                <div>
                    <span class="text-xl font-bold text-white">{{ number_format($exhibitionCount) }}</span> exhibitions
                </div>
            </div>
        </div>
    </header>

    {{-- Works grouped by exhibition --}}
    <div class="max-w-5xl mx-auto px-4 py-10">
        @foreach($galleries as $entry)
            <section class="mb-10" aria-label="Exhibition: {{ $entry['gallery']->title }}">
                <div class="flex items-baseline justify-between mb-4">
                    <div>
                        <a href="{{ $entry['gallery']->public_url }}" class="text-xl font-bold text-gray-100 hover:text-brand-300 transition">{{ $entry['gallery']->title }}</a>
                        @if($entry['gallery']->venueTemplate)
                            <span class="ml-3 text-xs px-2 py-0.5 rounded-full bg-gray-800 border border-gray-700 text-gray-400">{{ $entry['gallery']->venueTemplate->name }}</span>
                        @endif
                    </div>
                    <a href="{{ $entry['gallery']->public_url }}" class="text-sm text-brand-400 hover:text-brand-300 transition whitespace-nowrap">Enter gallery →</a>
                </div>
                @if($entry['gallery']->description)
                    <p class="text-gray-400 text-sm mb-4 max-w-2xl">{{ Str::limit($entry['gallery']->description, 200) }}</p>
                @endif
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    @foreach($entry['images'] as $img)
                        <a href="{{ url('/gallery/' . $entry['gallery']->slug . '/artwork/' . $img->id) }}" class="group block">
                            <div class="aspect-square bg-gray-900 rounded-lg overflow-hidden border border-gray-800 group-hover:border-brand-500 transition">
                                <img src="{{ $img->public_url }}"
                                     srcset="{{ $img->srcset }}"
                                     sizes="(max-width: 640px) 50vw, 20vw"
                                     alt="{{ $img->title ?: $img->original_name }} by {{ $artist->name }}"
                                     loading="lazy" decoding="async"
                                     class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                            </div>
                            <div class="mt-2">
                                <p class="text-gray-200 text-sm font-medium truncate group-hover:text-brand-300 transition">{{ $img->title ?: $img->original_name }}</p>
                                @if($img->year)
                                    <p class="text-gray-500 text-xs">{{ $img->year }}@if($img->medium) · {{ $img->medium }}@endif</p>
                                @endif
                                @if($img->for_sale && $img->price)
                                    <p class="text-emerald-400 text-xs font-semibold mt-0.5">{{ $img->formattedPrice() }}</p>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

        @if($galleries->isEmpty())
            <div class="text-center py-24">
                <svg class="w-16 h-16 mx-auto mb-3 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <p class="text-gray-400">No public artworks by this artist yet.</p>
            </div>
        @endif

        {{-- Related artists (Iteration 3: internal linking via shared exhibitions) --}}
        @if($relatedArtists->isNotEmpty())
            <section class="mt-14" aria-label="Related artists">
                <h2 class="text-xl font-bold text-white mb-5">Artists exhibiting alongside {{ $artist->name }}</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-4">
                    @foreach($relatedArtists as $relatedArtist)
                        <a href="{{ route('artist.profile', $relatedArtist->slug) }}" class="group text-center">
                            <div class="w-16 h-16 mx-auto rounded-full bg-gradient-to-br from-brand-900/40 to-gray-900 border border-gray-700 group-hover:border-brand-500 transition flex items-center justify-center overflow-hidden">
                                @if($relatedArtist->portrait_url)
                                    <img src="{{ $relatedArtist->portrait_url }}" alt="{{ $relatedArtist->name }}" loading="lazy" class="w-full h-full object-cover">
                                @else
                                    <span class="text-gray-500 font-bold">{{ $relatedArtist->initials }}</span>
                                @endif
                            </div>
                            <p class="text-gray-300 text-sm font-medium mt-2 truncate group-hover:text-brand-300 transition">{{ $relatedArtist->name }}</p>
                            @if($relatedArtist->public_works_count)
                                <p class="text-gray-600 text-xs">{{ $relatedArtist->public_works_count }} shared</p>
                            @endif
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Cross-links to discovery hubs --}}
        <nav class="mt-12 pt-8 border-t border-gray-800 flex flex-wrap gap-4 text-sm" aria-label="More on Exospace">
            <a href="{{ route('discover') }}" class="text-brand-400 hover:text-brand-300 transition">Browse 3D exhibitions</a>
            <a href="{{ route('artists.index') }}" class="text-brand-400 hover:text-brand-300 transition">Browse all artists</a>
            <a href="{{ route('venues.index') }}" class="text-brand-400 hover:text-brand-300 transition">Browse venues</a>
        </nav>
    </div>
@endsection
