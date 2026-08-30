<x-app-layout>
    <x-slot name="header">
        <x-page-header title="New venue template" :back="route('super.venues.index')" backLabel="Venues"/>
    </x-slot>

    <div class="page-shell-mid">
        <form method="POST" action="{{ route('super.venues.store') }}" enctype="multipart/form-data">
            @csrf

            @include('super-admin.venues._form-fields', ['venue' => $venue, 'categories' => $categories, 'layouts' => $layouts])

            <div class="flex justify-end gap-3 pt-2">
                <a href="{{ route('super.venues.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Create venue</button>
            </div>
        </form>
    </div>
</x-app-layout>
