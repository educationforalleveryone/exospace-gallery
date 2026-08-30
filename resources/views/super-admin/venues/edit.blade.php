<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'Edit: '.$venue->name" :back="route('super.venues.index')" backLabel="Venues">
            <x-slot:meta>
                @if($venue->is_draft)
                    <span class="badge badge-warning">Draft</span>
                @endif
            </x-slot:meta>
        </x-page-header>
    </x-slot>

    <div class="page-shell-mid">
        <form method="POST" action="{{ route('super.venues.update', $venue) }}" enctype="multipart/form-data">
            @csrf @method('PUT')

            @include('super-admin.venues._form-fields', ['venue' => $venue, 'categories' => $categories, 'layouts' => $layouts])

            <div class="flex justify-between gap-3 pt-2">
                <a href="{{ route('super.venues.index') }}"
                   class="px-4 py-2 rounded-lg bg-gray-700 text-gray-300 hover:bg-gray-600 text-sm transition">Cancel</a>
                <button type="submit"
                        class="px-5 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold transition">
                    Save changes
                </button>
            </div>
        </form>

        {{-- Danger zone --}}
        <div class="mt-8 bg-red-950/20 border border-red-900/40 rounded-xl p-5">
            <h3 class="text-red-300 font-semibold text-sm mb-2">Danger zone</h3>
            <p class="text-xs text-red-400/70 mb-3">Deleting a venue resets every gallery using it back to the default "white-cube" venue. This cannot be undone.</p>
            <form method="POST" action="{{ route('super.venues.destroy', $venue) }}"
                  data-confirm="Delete venue &quot;{{ $venue->name }}&quot;? Galleries using it will fall back to the default venue.">
                @csrf @method('DELETE')
                <button type="submit" class="px-4 py-2 rounded-lg bg-red-900/40 hover:bg-red-900/60 text-red-300 text-sm font-medium transition border border-red-800/40">
                    Delete this venue
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
