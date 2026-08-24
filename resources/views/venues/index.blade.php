{{--
    Venue directory (SEO OS Iteration 2) — crawlable hub for venue templates.
--}}
@extends('layouts.public')

@section('content')
    <div class="bg-gradient-to-br from-gray-900 via-purple-950/30 to-gray-900 border-b border-gray-800">
        <div class="max-w-7xl mx-auto px-4 py-16">
            <div class="text-center">
                <p class="text-purple-400 text-sm font-semibold tracking-widest uppercase mb-3">Venues</p>
                <h1 class="text-4xl md:text-5xl font-extrabold text-white mb-4">3D Venue Templates</h1>
                <p class="text-gray-400 text-lg max-w-2xl mx-auto">The spaces that host virtual exhibitions — museums, warehouses, lofts, and galleries. See live exhibitions built with each venue.</p>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 py-10">
        <div class="mb-6">
            <x-breadcrumbs :crumbs="$breadcrumbs" />
        </div>

        @if($venues->count() > 0)
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                @foreach($venues as $venue)
                    <a href="{{ route('venues.show', $venue->slug) }}" class="group bg-gray-800/60 border border-gray-700/50 rounded-xl overflow-hidden hover:border-purple-500 hover:-translate-y-1 hover:shadow-xl hover:shadow-purple-900/20 transition-all duration-300">
                        <div class="aspect-[4/3] bg-gradient-to-br from-purple-900/30 to-gray-900 overflow-hidden relative">
                            @if($venue->thumbnail_path)
                                <img src="{{ asset('storage/' . $venue->thumbnail_path) }}"
                                     alt="{{ $venue->name }} venue template"
                                     loading="lazy" decoding="async"
                                     class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                            @endif
                            @if($venue->category && isset(\App\Models\VenueTemplate::CATEGORIES[$venue->category]))
                                <span class="absolute top-2 left-2 px-2 py-0.5 rounded-full text-xs font-medium bg-black/70 backdrop-blur-sm text-gray-200 border border-white/10">{{ \App\Models\VenueTemplate::CATEGORIES[$venue->category] }}</span>
                            @endif
                        </div>
                        <div class="p-4">
                            <h3 class="text-gray-100 font-semibold leading-tight mb-1 group-hover:text-purple-300 transition-colors">{{ $venue->name }}</h3>
                            @if($venue->description)
                                <p class="text-gray-400 text-sm line-clamp-2">{{ Str::limit($venue->description, 120) }}</p>
                            @endif
                            <p class="text-gray-500 text-xs mt-2">{{ $venue->public_galleries_count }} live {{ Str::plural('exhibition', $venue->public_galleries_count) }}</p>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-10">
                {{ $venues->links() }}
            </div>
        @else
            <div class="text-center py-24">
                <p class="text-gray-400">No published venues with live exhibitions yet.</p>
            </div>
        @endif

        <nav class="mt-12 pt-8 border-t border-gray-800 flex flex-wrap gap-4 text-sm" aria-label="Discover more">
            <a href="{{ route('discover') }}" class="text-purple-400 hover:text-purple-300 transition">Browse 3D exhibitions</a>
            <a href="{{ route('artists.index') }}" class="text-purple-400 hover:text-purple-300 transition">Browse artists</a>
        </nav>
    </div>
@endsection
