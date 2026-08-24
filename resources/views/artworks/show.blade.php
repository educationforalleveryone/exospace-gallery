{{--
    Artwork landing page (SEO OS Iteration 2).

    The indexable leaf node of the public web graph:
      artwork → artist profile
      artwork → exhibition (3D)
      artwork → sibling artworks
      breadcrumbs back to Discover

    Quality gate: the controller marks thin artworks noindex. The visual
    presentation is identical either way — noindex is an indexing signal,
    not a content change.
--}}
@extends('layouts.public')

@section('content')
    <div class="max-w-6xl mx-auto px-4 pt-6">
        <x-breadcrumbs :crumbs="$breadcrumbs" />
    </div>

    <div class="max-w-6xl mx-auto px-4 py-8 grid lg:grid-cols-5 gap-10">

        {{-- Artwork image --}}
        <div class="lg:col-span-3">
            <figure class="bg-gray-900 rounded-2xl border border-gray-800 overflow-hidden">
                <img src="{{ $artwork->public_url }}"
                     srcset="{{ $artwork->srcset }}"
                     sizes="(max-width: 1024px) 100vw, 60vw"
                     alt="{{ $artwork->title ?: $artwork->original_name ?: 'Artwork' }}{{ $artwork->artist ? ' by ' . $artwork->artist->name : '' }}"
                     class="w-full h-auto object-contain"
                     width="{{ $artwork->width }}" height="{{ $artwork->height }}">
            </figure>

            @if($artwork->description)
                <div class="mt-8">
                    <h2 class="text-lg font-bold text-white mb-3">About this work</h2>
                    <p class="text-gray-300 leading-relaxed whitespace-pre-line">{{ $artwork->description }}</p>
                </div>
            @endif

            @if($artwork->external_url)
                <a href="{{ $artwork->external_url }}" target="_blank" rel="noopener" class="inline-block mt-6 text-purple-400 hover:text-purple-300 transition text-sm">
                    View on the artist's site →
                </a>
            @endif
        </div>

        {{-- Metadata sidebar --}}
        <aside class="lg:col-span-2">
            <header class="mb-6">
                <p class="text-purple-400 text-xs font-semibold tracking-widest uppercase mb-2">Artwork</p>
                <h1 class="text-3xl font-extrabold text-white leading-tight">{{ $artwork->title ?: $artwork->original_name ?: 'Untitled' }}</h1>
                @if($artwork->artist)
                    <p class="text-gray-400 mt-2">
                        by <a href="{{ route('artist.profile', $artwork->artist->slug) }}" class="text-purple-400 hover:text-purple-300 transition font-medium">{{ $artwork->artist->name }}</a>
                    </p>
                @endif
            </header>

            @if($artwork->for_sale && $artwork->price)
                <div class="mb-6 p-4 rounded-xl bg-green-900/20 border border-green-700/30">
                    <p class="text-green-300 font-bold text-lg">{{ $artwork->formattedPrice() }}</p>
                    <p class="text-green-400/70 text-xs mt-0.5">Available for purchase</p>
                </div>
            @endif

            {{-- Factual metadata table --}}
            <dl class="space-y-3 text-sm border-t border-gray-800 pt-6">
                @if($artwork->medium)
                    <div class="flex gap-4">
                        <dt class="text-gray-500 w-28 flex-shrink-0">Medium</dt>
                        <dd class="text-gray-200">{{ $artwork->medium }}</dd>
                    </div>
                @endif
                @if($artwork->year)
                    <div class="flex gap-4">
                        <dt class="text-gray-500 w-28 flex-shrink-0">Year</dt>
                        <dd class="text-gray-200">{{ $artwork->year }}</dd>
                    </div>
                @endif
                @if($artwork->dimensions)
                    <div class="flex gap-4">
                        <dt class="text-gray-500 w-28 flex-shrink-0">Dimensions</dt>
                        <dd class="text-gray-200">{{ $artwork->dimensions }}</dd>
                    </div>
                @endif
                @if($artwork->formattedEdition())
                    <div class="flex gap-4">
                        <dt class="text-gray-500 w-28 flex-shrink-0">Edition</dt>
                        <dd class="text-gray-200">{{ $artwork->formattedEdition() }}</dd>
                    </div>
                @endif
                @if($artwork->width && $artwork->height)
                    <div class="flex gap-4">
                        <dt class="text-gray-500 w-28 flex-shrink-0">Image size</dt>
                        <dd class="text-gray-200">{{ $artwork->width }} × {{ $artwork->height }} px</dd>
                    </div>
                @endif
            </dl>

            {{-- Exhibition context card --}}
            <div class="mt-8 p-5 rounded-xl bg-gray-800/60 border border-gray-700/50">
                <p class="text-gray-500 text-xs uppercase tracking-wider font-semibold mb-2">On view in</p>
                <a href="{{ $gallery->public_url }}" class="group flex items-center gap-3">
                    <div class="w-16 h-16 rounded-lg overflow-hidden bg-gray-900 flex-shrink-0 border border-gray-700">
                        @if($gallery->coverImage)
                            <img src="{{ $gallery->coverImage->public_url }}" alt="{{ $gallery->title }}" loading="lazy" class="w-full h-full object-cover">
                        @endif
                    </div>
                    <div>
                        <p class="text-gray-100 font-semibold group-hover:text-purple-300 transition leading-tight">{{ $gallery->title }}</p>
                        <p class="text-gray-500 text-xs mt-0.5">
                            {{ $gallery->images->count() }} {{ Str::plural('artwork', $gallery->images->count()) }}
                            @if($gallery->venueTemplate) · {{ $gallery->venueTemplate->name }} @endif
                        </p>
                        <p class="text-purple-400 text-xs mt-1">Walk the 3D exhibition →</p>
                    </div>
                </a>
            </div>
        </aside>
    </div>

    {{-- Sibling works (internal linking: artwork → artworks) --}}
    @if($siblings->isNotEmpty())
    <section class="max-w-6xl mx-auto px-4 py-12 border-t border-gray-800" aria-label="More artworks in this exhibition">
        <h2 class="text-xl font-bold text-white mb-6">More artworks in {{ $gallery->title }}</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5">
            @foreach($siblings as $img)
                <a href="{{ url('/gallery/' . $gallery->slug . '/artwork/' . $img->id) }}" class="group block">
                    <div class="aspect-square bg-gray-900 rounded-lg overflow-hidden border border-gray-800 group-hover:border-purple-500 transition">
                        <img src="{{ $img->public_url }}"
                             srcset="{{ $img->srcset }}"
                             sizes="(max-width: 640px) 50vw, 25vw"
                             alt="{{ $img->title ?: $img->original_name ?: 'Artwork' }}"
                             loading="lazy" decoding="async"
                             class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                    </div>
                    <p class="text-gray-300 text-sm font-medium mt-2 truncate group-hover:text-purple-300 transition">{{ $img->title ?: $img->original_name ?: 'Untitled' }}</p>
                    @if($img->artist)
                        <p class="text-gray-500 text-xs truncate">{{ $img->artist->name }}</p>
                    @endif
                </a>
            @endforeach
        </div>
    </section>
    @endif

    <nav class="max-w-6xl mx-auto px-4 py-8 border-t border-gray-800 flex flex-wrap gap-4 text-sm" aria-label="More on Exospace">
        @if($artwork->artist)
            <a href="{{ route('artist.profile', $artwork->artist->slug) }}" class="text-purple-400 hover:text-purple-300 transition">More by {{ $artwork->artist->name }}</a>
        @endif
        <a href="{{ $gallery->public_url }}" class="text-purple-400 hover:text-purple-300 transition">Enter {{ $gallery->title }} in 3D</a>
        <a href="{{ route('discover') }}" class="text-purple-400 hover:text-purple-300 transition">Browse 3D exhibitions</a>
    </nav>
@endsection
