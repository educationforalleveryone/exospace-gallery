<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-100 leading-tight">
                {{ __('Create New Gallery') }}
            </h2>
            @if(isset($team))
                <p class="text-sm text-indigo-400 mt-1">Creating in team: <strong>{{ $team->name }}</strong></p>
            @endif
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-gray-800 overflow-hidden shadow-lg sm:rounded-lg p-6 border border-gray-700">
                
                <form action="{{ route('admin.galleries.store') }}" method="POST" enctype="multipart/form-data">
    @if(isset($team))
        <input type="hidden" name="team_id" value="{{ $team->id }}">
    @endif
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

                        <!-- Room Layout -->
                        <div class="mb-4 md:col-span-2">
                            <label class="block text-sm font-medium text-gray-200 mb-3">Room Layout *</label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">

                                <label class="layout-card cursor-pointer">
                                    <input type="radio" name="room_layout" value="square" class="sr-only layout-radio" {{ old('room_layout', 'square') == 'square' ? 'checked' : '' }}>
                                    <div class="layout-card-inner border-2 border-gray-600 rounded-lg p-3 text-center transition-all hover:border-purple-500">
                                        <svg viewBox="0 0 60 60" class="w-12 h-12 mx-auto mb-2"><rect x="8" y="8" width="44" height="44" rx="2" fill="none" stroke="#9ca3af" stroke-width="2.5"/><circle cx="14" cy="18" r="2" fill="#a78bfa"/><circle cx="14" cy="30" r="2" fill="#a78bfa"/><circle cx="14" cy="42" r="2" fill="#a78bfa"/><circle cx="46" cy="18" r="2" fill="#a78bfa"/><circle cx="46" cy="30" r="2" fill="#a78bfa"/><circle cx="46" cy="42" r="2" fill="#a78bfa"/><circle cx="26" cy="10" r="2" fill="#a78bfa"/><circle cx="34" cy="10" r="2" fill="#a78bfa"/></svg>
                                        <div class="text-sm font-medium text-gray-200">Square</div>
                                        <div class="text-xs text-gray-500 mt-1">Classic room</div>
                                    </div>
                                </label>

                                <label class="layout-card cursor-pointer">
                                    <input type="radio" name="room_layout" value="corridor" class="sr-only layout-radio" {{ old('room_layout') == 'corridor' ? 'checked' : '' }}>
                                    <div class="layout-card-inner border-2 border-gray-600 rounded-lg p-3 text-center transition-all hover:border-purple-500">
                                        <svg viewBox="0 0 60 60" class="w-12 h-12 mx-auto mb-2"><rect x="4" y="20" width="52" height="20" rx="2" fill="none" stroke="#9ca3af" stroke-width="2.5"/><circle cx="10" cy="25" r="2" fill="#a78bfa"/><circle cx="10" cy="35" r="2" fill="#a78bfa"/><circle cx="50" cy="25" r="2" fill="#a78bfa"/><circle cx="50" cy="35" r="2" fill="#a78bfa"/><circle cx="22" cy="22" r="2" fill="#a78bfa"/><circle cx="30" cy="22" r="2" fill="#a78bfa"/><circle cx="38" cy="22" r="2" fill="#a78bfa"/><circle cx="22" cy="38" r="2" fill="#a78bfa"/><circle cx="30" cy="38" r="2" fill="#a78bfa"/><circle cx="38" cy="38" r="2" fill="#a78bfa"/></svg>
                                        <div class="text-sm font-medium text-gray-200">Corridor</div>
                                        <div class="text-xs text-gray-500 mt-1">Long hallway</div>
                                    </div>
                                </label>

                                <label class="layout-card cursor-pointer">
                                    <input type="radio" name="room_layout" value="l-shape" class="sr-only layout-radio" {{ old('room_layout') == 'l-shape' ? 'checked' : '' }}>
                                    <div class="layout-card-inner border-2 border-gray-600 rounded-lg p-3 text-center transition-all hover:border-purple-500">
                                        <svg viewBox="0 0 60 60" class="w-12 h-12 mx-auto mb-2"><polygon points="8,8 28,8 28,32 52,32 52,52 8,52" fill="none" stroke="#9ca3af" stroke-width="2.5" stroke-linejoin="round"/><circle cx="13" cy="15" r="2" fill="#a78bfa"/><circle cx="13" cy="25" r="2" fill="#a78bfa"/><circle cx="13" cy="42" r="2" fill="#a78bfa"/><circle cx="30" cy="48" r="2" fill="#a78bfa"/><circle cx="42" cy="48" r="2" fill="#a78bfa"/><circle cx="38" cy="34" r="2" fill="#a78bfa"/><circle cx="48" cy="34" r="2" fill="#a78bfa"/><circle cx="18" cy="10" r="2" fill="#a78bfa"/></svg>
                                        <div class="text-sm font-medium text-gray-200">L-Shape</div>
                                        <div class="text-xs text-gray-500 mt-1">Around a corner</div>
                                    </div>
                                </label>

                                <label class="layout-card cursor-pointer">
                                    <input type="radio" name="room_layout" value="rotunda" class="sr-only layout-radio" {{ old('room_layout') == 'rotunda' ? 'checked' : '' }}>
                                    <div class="layout-card-inner border-2 border-gray-600 rounded-lg p-3 text-center transition-all hover:border-purple-500">
                                        <svg viewBox="0 0 60 60" class="w-12 h-12 mx-auto mb-2"><circle cx="30" cy="30" r="22" fill="none" stroke="#9ca3af" stroke-width="2.5"/><circle cx="30" cy="10" r="2" fill="#a78bfa"/><circle cx="47" cy="19" r="2" fill="#a78bfa"/><circle cx="47" cy="41" r="2" fill="#a78bfa"/><circle cx="30" cy="50" r="2" fill="#a78bfa"/><circle cx="13" cy="41" r="2" fill="#a78bfa"/><circle cx="13" cy="19" r="2" fill="#a78bfa"/></svg>
                                        <div class="text-sm font-medium text-gray-200">Rotunda</div>
                                        <div class="text-xs text-gray-500 mt-1">Circular room</div>
                                    </div>
                                </label>

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

                    <!-- Exhibition Schedule (Pro Feature) -->
                    <div class="mb-6 p-6 bg-gray-900/50 rounded-lg border border-gray-600">
                        <div class="flex items-center gap-2 mb-1">
                            <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <label class="block text-sm font-medium text-gray-200">Exhibition Schedule</label>
                            @if(!auth()->user()->isPro())
                                <span class="text-xs bg-purple-600 text-white px-2 py-0.5 rounded-full ml-1">Pro Only</span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 mb-4">Set when this gallery opens and closes to the public. Visitors see a live countdown before opening, and a closed page after. Leave blank for always-open.</p>

                        @if(auth()->user()->isPro())
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-400 mb-1">Opens At</label>
                                    <input type="datetime-local" name="opens_at" value="{{ old('opens_at') }}"
                                        class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 shadow-sm focus:border-purple-500 focus:ring-purple-500 text-sm">
                                    <p class="text-xs text-gray-500 mt-1">Your local time. Leave blank to open immediately.</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-400 mb-1">Closes At</label>
                                    <input type="datetime-local" name="closes_at" value="{{ old('closes_at') }}"
                                        class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 shadow-sm focus:border-purple-500 focus:ring-purple-500 text-sm">
                                    <p class="text-xs text-gray-500 mt-1">Optional. Leave blank for no end date.</p>
                                </div>
                            </div>
                        @else
                            <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-6 text-center">
                                <svg class="w-12 h-12 text-gray-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <p class="text-gray-400 mb-2 text-sm">Schedule your exhibition opening and closing dates</p>
                                <p class="text-gray-500 text-xs mb-4">Visitors see a live countdown until opening. After closing, a stats page shows total visitors and days open.</p>
                                <a href="/pricing" class="inline-block bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-2 px-6 rounded-lg transition text-sm">
                                    Upgrade to Pro — $29
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

<script>
document.querySelectorAll('.layout-radio').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('.layout-card-inner').forEach(c => {
            c.classList.remove('border-purple-500', 'bg-purple-900/20');
            c.classList.add('border-gray-600');
        });
        if (radio.checked) {
            const inner = radio.closest('.layout-card').querySelector('.layout-card-inner');
            inner.classList.remove('border-gray-600');
            inner.classList.add('border-purple-500', 'bg-purple-900/20');
        }
    });
    if (radio.checked) {
        const inner = radio.closest('.layout-card').querySelector('.layout-card-inner');
        inner.classList.remove('border-gray-600');
        inner.classList.add('border-purple-500', 'bg-purple-900/20');
    }
});
</script>

</x-app-layout>