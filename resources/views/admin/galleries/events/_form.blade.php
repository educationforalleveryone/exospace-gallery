<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="@yield('form-title', 'New event')" :back="route('admin.galleries.events.index', $gallery)" backLabel="Events"/>
    </x-slot>

    <div class="page-shell-narrow">
        <form method="POST" action="@yield('form-action')">
            @csrf @yield('form-method')

            <div class="bg-gray-800 rounded-xl border border-gray-700 p-6 space-y-4">

                <div>
                    <label for="title" class="label-text mb-1.5">Title <span class="text-red-400" aria-hidden="true">*</span></label>
                    <input type="text" id="title" name="title" value="{{ old('title', $event->title) }}" required maxlength="200"
                           placeholder="Opening Reception: Reflections 2026"
                           class="input-base">
                    @error('title')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="type" class="label-text mb-1.5">Type</label>
                    <select id="type" name="type" class="input-base">
                        @foreach($types as $key => $label)
                            <option value="{{ $key }}" {{ old('type', $event->type) === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="event-description" class="label-text mb-1.5">Description</label>
                    <textarea name="description" id="event-description" rows="4" maxlength="2000"
                              class="input-base"
                              placeholder="What visitors can expect at this event…">{{ old('description', $event->description) }}</textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="starts_at" class="label-text mb-1.5">Starts at <span class="text-red-400" aria-hidden="true">*</span></label>
                        <input type="datetime-local" id="starts_at" name="starts_at" value="{{ old('starts_at', $event->starts_at ? $event->starts_at->format('Y-m-d\TH:i') : null) }}" required
                               class="input-base">
                    </div>
                    <div>
                        <label for="ends_at" class="label-text mb-1.5">Ends at <span class="text-gray-500 text-xs">(optional)</span></label>
                        <input type="datetime-local" id="ends_at" name="ends_at" value="{{ old('ends_at', $event->ends_at ? $event->ends_at->format('Y-m-d\TH:i') : null) }}"
                               class="input-base">
                    </div>
                </div>

                <div>
                    <label for="timezone" class="label-text mb-1.5">Timezone</label>
                    <input type="text" id="timezone" name="timezone" value="{{ old('timezone', $event->timezone ?? 'UTC') }}"
                           class="input-base font-mono">
                    <p class="text-xs text-gray-500 mt-1">IANA timezone, e.g. <code>America/New_York</code>, <code>Europe/Berlin</code>, <code>UTC</code>.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="location_name" class="label-text mb-1.5">Location name <span class="text-gray-500 text-xs">(optional)</span></label>
                        <input type="text" id="location_name" name="location_name" value="{{ old('location_name', $event->location_name) }}"
                               placeholder="Physical venue or 'Virtual'"
                               class="input-base">
                    </div>
                    <div>
                        <label for="location_url" class="label-text mb-1.5">Location URL <span class="text-gray-500 text-xs">(optional)</span></label>
                        <input type="url" id="location_url" name="location_url" value="{{ old('location_url', $event->location_url) }}"
                               placeholder="https://zoom.us/..."
                               class="input-base">
                    </div>
                </div>

                <div>
                    <label for="capacity" class="label-text mb-1.5">Capacity <span class="text-gray-500 text-xs">(blank = unlimited)</span></label>
                    <input type="number" id="capacity" name="capacity" value="{{ old('capacity', $event->capacity) }}" min="1"
                           class="input-base">
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-300">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $event->is_active ?? true) ? 'checked' : '' }}
                           class="rounded bg-gray-700 border-gray-600 text-purple-600 focus:ring-purple-500">
                    Published (visible to visitors)
                </label>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <a href="{{ route('admin.galleries.events.index', $gallery) }}" class="px-4 py-2 rounded-lg bg-gray-700 text-gray-300 hover:bg-gray-600 text-sm transition">Cancel</a>
                <button type="submit" class="px-5 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold transition">@yield('submit-label', 'Create event')</button>
            </div>
        </form>
    </div>
</x-app-layout>
