<x-app-layout>
    <x-slot name="header">
        <x-page-header title="New artist" :back="route('admin.artists.index')" backLabel="Artists"/>
    </x-slot>

    <div class="page-shell-narrow">
        <form method="POST" action="{{ route('admin.artists.store') }}" enctype="multipart/form-data">
            @csrf
            @include('admin.artists._form-fields', ['artist' => $artist])
            <div class="flex justify-end gap-3 pt-2">
                <a href="{{ route('admin.artists.index') }}" class="px-4 py-2 rounded-lg bg-gray-700 text-gray-300 hover:bg-gray-600 text-sm transition">Cancel</a>
                <button type="submit" class="px-5 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold transition">Create artist</button>
            </div>
        </form>
    </div>
</x-app-layout>
