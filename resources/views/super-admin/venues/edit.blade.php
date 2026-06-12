<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100">Edit: {{ $venue->name }}</h2>
    </x-slot>
    <div class="py-8 max-w-xl mx-auto px-4">
        <form method="POST" action="{{ route('super.venues.update', $venue) }}">
            @csrf @method('PUT')
            <div class="bg-gray-800 rounded-xl border border-gray-700 p-6 space-y-4">

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Name</label>
                    <input type="text" name="name" value="{{ old('name', $venue->name) }}" class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
                    @error('name')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Description</label>
                    <textarea name="description" rows="3" class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">{{ old('description', $venue->description) }}</textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Plan Required</label>
                        <select name="plan_required" class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
                            @foreach(['free','pro','studio'] as $plan)
                                <option value="{{ $plan }}" {{ old('plan_required', $venue->plan_required) === $plan ? 'selected' : '' }}>{{ ucfirst($plan) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Sort Order</label>
                        <input type="number" name="sort_order" value="{{ old('sort_order', $venue->sort_order) }}" class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Min Capacity</label>
                        <input type="number" name="capacity_min" value="{{ old('capacity_min', $venue->capacity_min) }}" class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1">Max Capacity <span class="text-gray-500">(blank = unlimited)</span></label>
                        <input type="number" name="capacity_max" value="{{ old('capacity_max', $venue->capacity_max) }}" class="w-full rounded-lg bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-purple-500">
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <a href="{{ route('super.venues.index') }}" class="px-4 py-2 rounded-lg bg-gray-700 text-gray-300 hover:bg-gray-600 text-sm transition">Cancel</a>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold transition">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>