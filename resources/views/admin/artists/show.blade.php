<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$artist->name" :back="route('admin.artists.index')" backLabel="Artists">
            <x-slot:actions>
                <a href="{{ route('admin.artists.edit', $artist) }}" class="btn btn-secondary btn-sm">Edit</a>
            </x-slot:actions>
        </x-page-header>
    </x-slot>

    <div class="page-shell-mid">

        {{-- Header card --}}
        <div class="bg-gray-800 border border-gray-700 rounded-xl p-6 mb-6">
            <div class="flex items-start gap-6">
                <div class="w-24 h-24 rounded-full overflow-hidden bg-gradient-to-br from-purple-900/40 to-gray-900 flex items-center justify-center flex-shrink-0">
                    @if($artist->portrait_url)
                        <img src="{{ $artist->portrait_url }}" alt="{{ $artist->name }}" class="w-full h-full object-cover">
                    @else
                        <span class="text-3xl font-bold text-gray-600">{{ $artist->initials }}</span>
                    @endif
                </div>
                <div class="flex-1">
                    <h1 class="text-2xl font-bold text-gray-100">{{ $artist->name }}</h1>
                    @if($artist->location)
                        <p class="text-gray-400 text-sm mt-1">{{ $artist->location }}</p>
                    @endif
                    @if($artist->bio)
                        <p class="text-gray-300 text-sm mt-3 leading-relaxed whitespace-pre-line">{{ $artist->bio }}</p>
                    @endif
                    <div class="flex flex-wrap gap-3 mt-4 text-xs">
                        @if($artist->website)
                            <a href="{{ $artist->website }}" target="_blank" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-gray-700 hover:bg-gray-600 text-gray-200 transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                                Website
                            </a>
                        @endif
                        @if($artist->instagram)
                            <a href="{{ $artist->instagram_url }}" target="_blank" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-gray-700 hover:bg-gray-600 text-gray-200 transition">
                                @{{ $artist->instagram }}
                            </a>
                        @endif
                        @if($artist->email)
                            <a href="mailto:{{ $artist->email }}" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-gray-700 hover:bg-gray-600 text-gray-200 transition">
                                {{ $artist->email }}
                            </a>
                        @endif
                        <a href="{{ route('artist.profile', $artist->slug) }}" target="_blank" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-purple-600/40 hover:bg-purple-600/60 text-purple-200 transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            Public profile
                        </a>
                    </div>
                </div>
            </div>
        </div>

        {{-- Stats --}}
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-gray-800 border border-gray-700 rounded-xl p-4 text-center">
                <div class="text-3xl font-bold text-purple-400">{{ $artist->images->count() }}</div>
                <div class="text-xs text-gray-500 uppercase tracking-wider mt-1">Artworks</div>
            </div>
            <div class="bg-gray-800 border border-gray-700 rounded-xl p-4 text-center">
                <div class="text-3xl font-bold text-purple-400">{{ $galleries->count() }}</div>
                <div class="text-xs text-gray-500 uppercase tracking-wider mt-1">Galleries</div>
            </div>
            <div class="bg-gray-800 border border-gray-700 rounded-xl p-4 text-center">
                <div class="text-3xl font-bold text-purple-400">{{ $artist->images->where('for_sale', true)->count() }}</div>
                <div class="text-xs text-gray-500 uppercase tracking-wider mt-1">For sale</div>
            </div>
        </div>

        {{-- Works by gallery --}}
        <h3 class="text-gray-200 font-semibold mb-4">Works by gallery</h3>
        @foreach($galleries as $entry)
            <div class="bg-gray-800 border border-gray-700 rounded-xl p-5 mb-4">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <a href="{{ route('admin.galleries.edit', $entry['gallery']) }}" class="text-gray-100 font-semibold hover:text-purple-300 transition">{{ $entry['gallery']->title }}</a>
                        @if($entry['gallery']->venueTemplate)
                            <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-gray-700/50 text-gray-400">{{ $entry['gallery']->venueTemplate->name }}</span>
                        @endif
                    </div>
                    <span class="text-xs text-gray-500">{{ $entry['images']->count() }} work{{ $entry['images']->count() === 1 ? '' : 's' }}</span>
                </div>
                <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-2">
                    @foreach($entry['images'] as $img)
                        <div class="aspect-square bg-gray-900 rounded-lg overflow-hidden border border-gray-700 group relative">
                            <img src="{{ asset($img->path) }}" alt="{{ $img->title ?: $img->original_name }}" class="w-full h-full object-cover">
                            <div class="absolute inset-0 bg-black/0 group-hover:bg-black/60 transition flex items-end p-2 opacity-0 group-hover:opacity-100">
                                <p class="text-white text-xs truncate">{{ $img->title ?: $img->original_name }}</p>
                            </div>
                            @if($img->for_sale)
                                <span class="absolute top-1 right-1 px-1.5 py-0.5 rounded bg-green-600/80 text-white text-xs font-bold">FOR SALE</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if($galleries->isEmpty())
            <div class="text-center py-16 text-gray-500">
                <p>This artist has no attributed artworks yet.</p>
                <p class="text-sm mt-1">Edit gallery images to attribute them to this artist.</p>
            </div>
        @endif
    </div>
</x-app-layout>
