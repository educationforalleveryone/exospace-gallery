<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-100 leading-tight">
                {{ __('Create New Gallery') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-gray-800 overflow-hidden shadow-lg sm:rounded-lg p-6 border border-gray-700">
                
                <form action="{{ route('admin.galleries.store') }}" method="POST">
                    @csrf
                    
                    <!-- Title -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-200 mb-2">Gallery Title *</label>
                        <input type="text" name="title" value="{{ old('title') }}" required 
                            class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 placeholder-gray-400 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                        @error('title')
                            <p class="text-red-400 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Description -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-200 mb-2">Description</label>
                        <textarea name="description" rows="3" 
                            class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 placeholder-gray-400 shadow-sm focus:border-purple-500 focus:ring-purple-500">{{ old('description') }}</textarea>
                        @error('description')
                            <p class="text-red-400 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Wall Texture -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-200 mb-2">Wall Texture *</label>
                            <select name="wall_texture" required
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                <option value="white" {{ old('wall_texture') == 'white' ? 'selected' : '' }}>White Museum</option>
                                <option value="concrete" {{ old('wall_texture') == 'concrete' ? 'selected' : '' }}>Concrete</option>
                                <option value="brick" {{ old('wall_texture') == 'brick' ? 'selected' : '' }}>Brick</option>
                                <option value="wood" {{ old('wall_texture') == 'wood' ? 'selected' : '' }}>Wood</option>
                            </select>
                        </div>

                        <!-- Floor Material -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-200 mb-2">Floor Material *</label>
                            <select name="floor_material" required
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                <option value="wood" {{ old('floor_material') == 'wood' ? 'selected' : '' }}>Wood</option>
                                <option value="marble" {{ old('floor_material') == 'marble' ? 'selected' : '' }}>Marble</option>
                                <option value="concrete" {{ old('floor_material') == 'concrete' ? 'selected' : '' }}>Concrete</option>
                            </select>
                        </div>

                        <!-- Frame Style -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-200 mb-2">Frame Style *</label>
                            <select name="frame_style" required
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                <option value="modern" {{ old('frame_style') == 'modern' ? 'selected' : '' }}>Modern (Black)</option>
                                <option value="classic" {{ old('frame_style') == 'classic' ? 'selected' : '' }}>Classic (Gold)</option>
                                <option value="minimal" {{ old('frame_style') == 'minimal' ? 'selected' : '' }}>Minimal (Frameless)</option>
                            </select>
                        </div>

                        <!-- Lighting -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-200 mb-2">Lighting *</label>
                            <select name="lighting_preset" required
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                <option value="bright" {{ old('lighting_preset') == 'bright' ? 'selected' : '' }}>Bright</option>
                                <option value="moody" {{ old('lighting_preset') == 'moody' ? 'selected' : '' }}>Moody</option>
                                <option value="dramatic" {{ old('lighting_preset') == 'dramatic' ? 'selected' : '' }}>Dramatic</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <a href="{{ route('admin.galleries.index') }}" class="bg-gray-700 hover:bg-gray-600 text-gray-100 font-semibold py-2 px-6 rounded-lg transition">
                            Cancel
                        </a>
                        <button type="submit" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-2 px-6 rounded-lg transition">
                            Create Gallery
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</x-app-layout>