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
                
                <form action="{{ route('admin.galleries.store') }}" method="POST" enctype="multipart/form-data">
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

                    <!-- Background Music (Pro Feature) -->
                    <div class="mb-6 mt-6 p-6 bg-gray-900/50 rounded-lg border border-gray-600">
                        <label class="block text-sm font-medium text-gray-200 mb-3">
                            🎵 Background Music
                            @if(!auth()->user()->isPro())
                                <span class="text-xs bg-purple-600 text-white px-2 py-0.5 rounded-full ml-2">Pro Only</span>
                            @endif
                        </label>
                        
                        @if(auth()->user()->isPro())
                            <!-- Show upload field for Pro users -->
                            <div class="space-y-3">
                                <input type="file" 
                                       name="audio" 
                                       accept=".mp3,.wav,.m4a"
                                       class="block w-full text-sm text-gray-300
                                              file:mr-4 file:py-2 file:px-4
                                              file:rounded-lg file:border-0
                                              file:text-sm file:font-semibold
                                              file:bg-purple-600 file:text-white
                                              hover:file:bg-purple-700
                                              cursor-pointer">
                                <p class="text-xs text-gray-400">Upload MP3, WAV, or M4A (Max 10MB). Music will loop in your 3D gallery.</p>
                            </div>
                        @else
                            <!-- Show locked state for Free users -->
                            <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-6 text-center">
                                <svg class="w-12 h-12 text-gray-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                <p class="text-gray-400 mb-4 text-sm">Background music is a <strong>Pro feature</strong></p>
                                <p class="text-gray-500 text-xs mb-4">Add ambient soundtracks to create immersive 3D experiences</p>
                                <a href="/pricing" class="inline-block bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-2 px-6 rounded-lg transition text-sm">
                                    Upgrade to Pro - $29
                                </a>
                            </div>
                        @endif
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