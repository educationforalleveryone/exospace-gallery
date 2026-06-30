<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.artists.index') }}" class="text-gray-400 hover:text-gray-200 transition text-sm">← Artists</a>
            <span class="text-gray-600">/</span>
            <h2 class="font-semibold text-xl text-gray-100">New artist</h2>
        </div>
    </x-slot>

    <div class="py-8 max-w-2xl mx-auto px-4">
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
