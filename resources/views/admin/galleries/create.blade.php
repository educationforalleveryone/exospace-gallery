<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="font-semibold text-xl text-gray-100 leading-tight">Create New Gallery</h2>
                @if(isset($team))
                    <p class="text-xs text-indigo-400 mt-0.5">Creating in <span class="font-semibold">{{ $team->name }}</span></p>
                @endif
            </div>
            @if(Auth::user()->galleries()->count() === 0)
            <div class="flex items-center gap-2 text-xs text-gray-500">
                <span class="flex items-center gap-1.5 text-green-400">
                    <span class="w-5 h-5 rounded-full bg-green-500/20 border border-green-500 inline-flex items-center justify-center flex-shrink-0">
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    </span>
                    Account
                </span>
                <span class="text-gray-700">→</span>
                <span class="flex items-center gap-1.5 text-purple-300 font-semibold">
                    <span class="w-5 h-5 rounded-full bg-purple-600 inline-flex items-center justify-center flex-shrink-0"><span style="font-size:9px;font-weight:700;color:white">2</span></span>
                    Gallery
                </span>
                <span class="text-gray-700">→</span>
                <span class="flex items-center gap-1.5 text-gray-600">
                    <span class="w-5 h-5 rounded-full bg-gray-700 border border-gray-600 inline-flex items-center justify-center flex-shrink-0"><span style="font-size:9px;font-weight:700;color:#6b7280">3</span></span>
                    Share
                </span>
            </div>
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

                    @if(Auth::user()->galleries()->count() === 0)
                    <div class="flex items-start gap-3 bg-purple-900/20 border border-purple-500/20 rounded-lg px-4 py-3 mb-5">
                        <svg class="w-4 h-4 text-purple-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                        <p class="text-xs text-purple-200 leading-relaxed">
                            <span class="font-semibold">Tip:</span> Give it a clear title — it becomes the page name when you share the link. Defaults below are fine for your first gallery; you can change everything later.
                        </p>
                    </div>
                    @endif

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

                    {{-- Advanced settings: collapsible, open by default for Pro users --}}
                    <div x-data="{ open: {{ Auth::user()->isPro() ? 'true' : 'false' }} }" class="mt-6">
                        <button type="button" @click="open = !open"
                                class="w-full flex items-center gap-2 text-sm font-medium text-gray-400 hover:text-gray-200 transition border-t border-gray-700/60 pt-4 pb-2 text-left">
                            <svg class="w-4 h-4 text-purple-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                            Advanced settings
                            @if(!Auth::user()->isPro())
                                <span class="text-xs bg-gray-700/80 text-gray-400 px-1.5 py-0.5 rounded ml-0.5">Pro features inside</span>
                            @endif
                            <svg class="w-4 h-4 ml-auto flex-shrink-0 transition-transform duration-200" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>

                        <div x-show="open"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0">

                    <!-- Background Music (Pro Feature) -->
                    <div class="mb-6 mt-2 p-6 bg-gray-900/50 rounded-lg border border-gray-600">
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
                            <div class="flex items-center justify-between gap-4 bg-gray-800/60 border border-dashed border-gray-600 rounded-lg px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    <p class="text-sm text-gray-400">Add ambient audio to your gallery — ambient soundscapes, custom tracks, anything MP3.</p>
                                </div>
                                <a href="/pricing" class="flex-shrink-0 text-xs font-semibold text-purple-400 hover:text-purple-300 border border-purple-600/40 hover:border-purple-500 px-3 py-1.5 rounded-lg transition whitespace-nowrap">
                                    Pro — $29
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
                            <div class="flex items-center justify-between gap-4 bg-gray-800/60 border border-dashed border-gray-600 rounded-lg px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    <p class="text-sm text-gray-400">Set an opening date — visitors see a live countdown. Close it automatically after the run ends.</p>
                                </div>
                                <a href="/pricing" class="flex-shrink-0 text-xs font-semibold text-purple-400 hover:text-purple-300 border border-purple-600/40 hover:border-purple-500 px-3 py-1.5 rounded-lg transition whitespace-nowrap">
                                    Pro — $29
                                </a>
                            </div>
                        @endif
                    </div>

                    </div>{{-- /x-show --}}
                    </div>{{-- /x-data advanced --}}

                    <div class="mt-6 flex justify-end gap-3">
                        <a href="{{ route('admin.galleries.index') }}" class="bg-gray-700 hover:bg-gray-600 text-gray-100 font-semibold py-2 px-6 rounded-lg transition">
                            Cancel
                        </a>
                        <button type="submit" id="create-gallery-btn"
                                class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-2 px-6 rounded-lg transition inline-flex items-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed">
                            <svg id="create-gallery-spinner" class="hidden animate-spin w-4 h-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                            <span id="create-gallery-label">Create Gallery</span>
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
document.querySelector('form').addEventListener('submit', function() {
    const btn = document.getElementById('create-gallery-btn');
    const label = document.getElementById('create-gallery-label');
    const spinner = document.getElementById('create-gallery-spinner');
    btn.disabled = true;
    label.textContent = 'Creating…';
    spinner.classList.remove('hidden');
});
</script>

</x-app-layout>