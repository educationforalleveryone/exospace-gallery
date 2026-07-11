<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.artists.index') }}" class="text-gray-400 hover:text-gray-200 transition text-sm">← Artists</a>
            <span class="text-gray-600">/</span>
            <h2 class="font-semibold text-xl text-gray-100">Edit: {{ $artist->name }}</h2>
        </div>
    </x-slot>

    <div class="py-8 max-w-2xl mx-auto px-4">
        <form method="POST" action="{{ route('admin.artists.update', $artist) }}" enctype="multipart/form-data">
            @csrf @method('PUT')
            @include('admin.artists._form-fields', ['artist' => $artist])
            <div class="flex justify-end gap-3 pt-2">
                <a href="{{ route('admin.artists.index') }}" class="px-4 py-2 rounded-lg bg-gray-700 text-gray-300 hover:bg-gray-600 text-sm transition">Cancel</a>
                <button type="submit" class="px-5 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold transition">Save changes</button>
            </div>
        </form>

        {{-- Danger zone --}}
        <div class="mt-8 bg-red-950/20 border border-red-900/40 rounded-xl p-5">
            <h3 class="text-red-300 font-semibold text-sm mb-2">Danger zone</h3>
            <p class="text-xs text-red-400/70 mb-3">Deleting an artist leaves their artworks intact but removes attribution.</p>
            <form method="POST" action="{{ route('admin.artists.destroy', $artist) }}"
                  data-confirm="Delete artist &quot;{{ addslashes($artist->name) }}&quot;?">
                @csrf @method('DELETE')
                <button type="submit" class="px-4 py-2 rounded-lg bg-red-900/40 hover:bg-red-900/60 text-red-300 text-sm font-medium transition border border-red-800/40">Delete artist</button>
            </form>
        </div>
    </div>
</x-app-layout>
