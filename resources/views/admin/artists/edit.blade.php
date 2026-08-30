<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'Edit: '.$artist->name" :back="route('admin.artists.index')" backLabel="Artists"/>
    </x-slot>

    <div class="page-shell-narrow">
        <form method="POST" action="{{ route('admin.artists.update', $artist) }}" enctype="multipart/form-data">
            @csrf @method('PUT')
            @include('admin.artists._form-fields', ['artist' => $artist])
            <div class="flex justify-end gap-3 pt-2">
                <a href="{{ route('admin.artists.index') }}" class="px-4 py-2 rounded-lg bg-gray-700 text-gray-300 hover:bg-gray-600 text-sm transition">Cancel</a>
                <button type="submit" class="px-5 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold transition">Save changes</button>
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
