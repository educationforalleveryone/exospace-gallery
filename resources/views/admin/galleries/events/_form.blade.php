<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.galleries.events.index', $gallery) }}" class="text-gray-400 hover:text-gray-200 transition text-sm">← Events</a>
            <span class="text-gray-600">/</span>
            <h2 class="font-semibold text-xl text-gray-100">@yield('form-title', 'New event')</h2>
        </div>
    </x-slot>

    <div class="py-8 max-w-2xl mx-auto px-4">
        <form method="POST" action="@yield('form-action')">
            @csrf @yield('form-method')

            <div class="bg-gray-800 rounded-xl border border-gray-700 p-6 space-y-4">

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Title <span class="text-red-400">*</span></label>
                    <input type="text" name="title" value="{{ old('title', $event->title) }}" required maxlength="200"
                           placeholder="Opening Reception: Reflections 2026"
                           class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
                    @error('title')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Type</label>
                    <select name="type" class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
                        @foreach($types as $key => $label)
                            <option value="{{ $key }}" {{ old('type', $event->type) === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Description</label>
                    <textarea name="description" rows="4" maxlength="2000"
                              class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500"
                              placeholder="What visitors can expect at this event…">{{ old('description', $event->description) }}</textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Starts at <span class="text-red-400">*</span></label>
                        <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $event->starts_at ? $event->starts_at->format('Y-m-d\TH:i') : null) }}" required
                               class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Ends at <span class="text-gray-500 text-xs">(optional)</span></label>
                        <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $event->ends_at ? $event->ends_at->format('Y-m-d\TH:i') : null) }}"
                               class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Timezone</label>
                    <input type="text" name="timezone" value="{{ old('timezone', $event->timezone ?? 'UTC') }}"
                           class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500 text-sm font-mono">
                    <p class="text-xs text-gray-500 mt-1">IANA timezone, e.g. <code>America/New_York</code>, <code>Europe/Berlin</code>, <code>UTC</code>.</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Location name <span class="text-gray-500 text-xs">(optional)</span></label>
                        <input type="text" name="location_name" value="{{ old('location_name', $event->location_name) }}"
                               placeholder="Physical venue or 'Virtual'"
                               class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Location URL <span class="text-gray-500 text-xs">(optional)</span></label>
                        <input type="url" name="location_url" value="{{ old('location_url', $event->location_url) }}"
                               placeholder="https://zoom.us/..."
                               class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Capacity <span class="text-gray-500 text-xs">(blank = unlimited)</span></label>
                    <input type="number" name="capacity" value="{{ old('capacity', $event->capacity) }}" min="1"
                           class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
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
