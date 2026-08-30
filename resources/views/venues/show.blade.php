{{--
    Venue detail (SEO OS Iteration 2): venue template + the live public
    exhibitions built with it. Internal links flow both ways — every
    exhibition card links out, and exhibitions link back via breadcrumbs.
--}}
@extends('layouts.public')

@section('content')
    <div class="bg-gradient-to-br from-gray-900 via-brand-950/30 to-gray-900 border-b border-gray-800">
        <div class="max-w-page mx-auto px-4 py-14">
            <div class="max-w-3xl">
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
                    <a href="{{ $gallery->public_url }}" class="group bg-gray-800/60 border border-gray-700/50 rounded-xl overflow-hidden hover:border-brand-500 hover:-translate-y-1 hover:shadow-xl hover:shadow-brand-900/20 transition-all duration-300">
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
@endsection
