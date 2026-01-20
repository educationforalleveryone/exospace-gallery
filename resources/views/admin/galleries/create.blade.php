<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Create New Gallery') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                
                <form action="{{ route('admin.galleries.store') }}" method="POST">
                    @csrf
                    
                    <!-- Title -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Gallery Title *</label>
                        <input type="text" name="title" value="{{ old('title') }}" required 
                            class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('title')
                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Description -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
                        <textarea name="description" rows="3" 
                            class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('description') }}</textarea>
                        @error('description')
                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Wall Texture -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Wall Texture *</label>
                            <select name="wall_texture" required
                                class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="white" {{ old('wall_texture') == 'white' ? 'selected' : '' }}>White Museum</option>
                                <option value="concrete" {{ old('wall_texture') == 'concrete' ? 'selected' : '' }}>Concrete</option>
                                <option value="brick" {{ old('wall_texture') == 'brick' ? 'selected' : '' }}>Brick</option>
                                <option value="wood" {{ old('wall_texture') == 'wood' ? 'selected' : '' }}>Wood</option>
                            </select>
                        </div>

                        <!-- Floor Material -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Floor Material *</label>
                            <select name="floor_material" required
                                class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="wood" {{ old('floor_material') == 'wood' ? 'selected' : '' }}>Wood</option>
                                <option value="marble" {{ old('floor_material') == 'marble' ? 'selected' : '' }}>Marble</option>
                                <option value="concrete" {{ old('floor_material') == 'concrete' ? 'selected' : '' }}>Concrete</option>
                            </select>
                        </div>

                        <!-- Frame Style -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Frame Style *</label>
                            <select name="frame_style" required
                                class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="modern" {{ old('frame_style') == 'modern' ? 'selected' : '' }}>Modern (Black)</option>
                                <option value="classic" {{ old('frame_style') == 'classic' ? 'selected' : '' }}>Classic (Gold)</option>
                                <option value="minimal" {{ old('frame_style') == 'minimal' ? 'selected' : '' }}>Minimal (Frameless)</option>
                            </select>
                        </div>

                        <!-- Lighting -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Lighting *</label>
                            <select name="lighting_preset" required
                                class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="bright" {{ old('lighting_preset') == 'bright' ? 'selected' : '' }}>Bright</option>
                                <option value="moody" {{ old('lighting_preset') == 'moody' ? 'selected' : '' }}>Moody</option>
                                <option value="dramatic" {{ old('lighting_preset') == 'dramatic' ? 'selected' : '' }}>Dramatic</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <a href="{{ route('admin.galleries.index') }}" class="bg-gray-300 hover:bg-gray-400 dark:bg-gray-600 dark:hover:bg-gray-500 text-gray-800 dark:text-gray-100 font-bold py-2 px-4 rounded">
                            Cancel
                        </a>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                            Create Gallery
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</x-app-layout>