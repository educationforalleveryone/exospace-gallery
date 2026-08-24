{{--
    Artist directory (SEO OS Iteration 2) — the crawlable hub for the
    artist layer. Every public artist profile is one internal link away.
--}}
@extends('layouts.public')

@section('content')
    <div class="bg-gradient-to-br from-gray-900 via-purple-950/30 to-gray-900 border-b border-gray-800">
        <div class="max-w-7xl mx-auto px-4 py-16">
            <div class="text-center">
                <p class="text-purple-400 text-sm font-semibold tracking-widest uppercase mb-3">Artists</p>
                <h1 class="text-4xl md:text-5xl font-extrabold text-white mb-4">Artists Exhibiting in 3D</h1>
                <p class="text-gray-400 text-lg max-w-2xl mx-auto">Discover painters, photographers, sculptors, and digital artists showing their work in immersive virtual exhibitions.</p>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 py-10">
        <div class="mb-6">
            <x-breadcrumbs :crumbs="$breadcrumbs" />
        </div>

        @if($artists->count() > 0)
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-5">
                @foreach($artists as $artist)
                    <a href="{{ route('artist.profile', $artist->slug) }}" class="group bg-gray-800/60 border border-gray-700/50 rounded-xl overflow-hidden hover:border-purple-500 hover:-translate-y-1 hover:shadow-xl hover:shadow-purple-900/20 transition-all duration-300">
                        <div class="aspect-square bg-gradient-to-br from-purple-900/40 to-gray-900 relative overflow-hidden">
                            @php $coverImage = $covers[$artist->id] ?? null; @endphp
                            @if($coverImage)
                                <img src="{{ $coverImage->public_url }}"
                                     alt="Artwork by {{ $artist->name }}"
                                     loading="lazy" decoding="async"
                                     class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105 opacity-90 group-hover:opacity-100">
                            @endif
                            <div class="absolute inset-x-0 bottom-0 p-3 bg-gradient-to-t from-black/85 to-transparent">
                                <p class="text-white text-sm font-semibold truncate">{{ $artist->name }}</p>
                                @if($artist->location)
                                    <p class="text-gray-400 text-xs truncate">{{ $artist->location }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="p-3 flex items-center justify-between text-xs text-gray-500">
                            <span>{{ $artist->public_works_count }} {{ Str::plural('artwork', $artist->public_works_count) }}</span>
                            <span class="text-purple-400 group-hover:text-purple-300 transition">View →</span>
                        </div>
                    </a>
                @endforeach
            </div>

            {{-- Pagination --}}
            <div class="mt-10">
                {{ $artists->links() }}
            </div>
        @else
            <div class="text-center py-24">
                <p class="text-gray-400">No public artist profiles yet.</p>
                <a href="{{ route('discover') }}" class="inline-block mt-4 text-purple-400 hover:text-purple-300 transition">Browse 3D exhibitions instead →</a>
            </div>
        @endif

        <nav class="mt-12 pt-8 border-t border-gray-800 flex flex-wrap gap-4 text-sm" aria-label="Discover more">
            <a href="{{ route('discover') }}" class="text-purple-400 hover:text-purple-300 transition">Browse 3D exhibitions</a>
            <a href="{{ route('venues.index') }}" class="text-purple-400 hover:text-purple-300 transition">Browse venue templates</a>
        </nav>
    </div>
@endsection
