<x-app-layout>
    <x-slot name="header">
        <x-page-header title="New artist" :back="route('admin.artists.index')" backLabel="Artists"/>
    </x-slot>

    <div class="page-shell-narrow">
        <form method="POST" action="{{ route('admin.artists.store') }}" enctype="multipart/form-data">
            @csrf
            @include('admin.artists._form-fields', ['artist' => $artist])
            <div class="flex justify-end gap-3 pt-2">
                <a href="{{ route('admin.artists.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Create artist</button>
            </div>
        </form>
    </div>
</x-app-layout>
