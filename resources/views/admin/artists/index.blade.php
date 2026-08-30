<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Artists" :description="'Manage artist profiles for attribution · '.$artists->total().' total'">
            <x-slot:actions>
                <a href="{{ route('admin.artists.create') }}" class="btn btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New artist
                </a>
            </x-slot:actions>
        </x-page-header>
    </x-slot>

    <div class="page-shell">

        {{-- Search --}}
        <form method="GET" class="mb-5">
            <input type="text" name="q" value="{{ request('q') }}"
                   placeholder="Search artists by name, bio, or location…"
                   class="input-base max-w-md">
        </form>

        {{-- Grid --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
            @forelse($artists as $artist)
                <div class="card card-interactive card-lift overflow-hidden">
                    <a href="{{ route('admin.artists.show', $artist) }}">
                        {{-- Portrait or initials --}}
                        <div class="aspect-square bg-gradient-to-br from-brand-900/20 to-gray-900 flex items-center justify-center overflow-hidden">
                            @if($artist->portrait_url)
                                <img src="{{ $artist->portrait_url }}" alt="{{ $artist->name }}" class="w-full h-full object-cover">
                            @else
                                <span class="text-5xl font-bold text-gray-600">{{ $artist->initials }}</span>
                            @endif
                        </div>
                        <div class="p-4">
                            <h2 class="text-gray-100 font-semibold truncate">{{ $artist->name }}</h2>
                            @if($artist->location)
                                <p class="text-gray-400 text-xs mt-0.5 truncate">{{ $artist->location }}</p>
                            @endif
                            <div class="flex items-center justify-between mt-3 text-xs">
                                <span class="text-gray-500">{{ $artist->images_count }} artwork{{ $artist->images_count === 1 ? '' : 's' }}</span>
                                <span class="text-brand-400 hover:text-brand-300">View →</span>
                            </div>
                        </div>
                    </a>
                    <div class="px-4 pb-3 flex gap-2">
                        <a href="{{ route('admin.artists.edit', $artist) }}" class="btn btn-sm btn-secondary flex-1">Edit</a>
                        <form method="POST" action="{{ route('admin.artists.destroy', $artist) }}"
                              data-confirm="Delete artist &quot;{{ addslashes($artist->name) }}&quot;? Their artworks will remain but become unattributed." data-confirm-button="Delete"
                              class="inline">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-danger-ghost">Delete</button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="col-span-full text-center py-16">
                    <svg class="w-16 h-16 mx-auto mb-3 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <p class="text-gray-400 mb-1">No artists yet.</p>
                    <p class="text-gray-600 text-sm mb-4">Create your first artist profile to attribute artworks.</p>
                    <a href="{{ route('admin.artists.create') }}" class="btn btn-primary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        New artist
                    </a>
                </div>
            @endforelse
        </div>

        <div class="mt-6">
            {{ $artists->withQueryString()->links() }}
        </div>
    </div>
</x-app-layout>
