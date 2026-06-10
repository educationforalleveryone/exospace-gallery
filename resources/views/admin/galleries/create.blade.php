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

                    {{-- Hidden fields populated by venue selection --}}
                    <input type="hidden" name="wall_texture"    id="input_wall_texture"    value="{{ old('wall_texture', 'white') }}">
                    <input type="hidden" name="floor_material"  id="input_floor_material"  value="{{ old('floor_material', 'concrete') }}">
                    <input type="hidden" name="frame_style"     id="input_frame_style"     value="{{ old('frame_style', 'minimal') }}">
                    <input type="hidden" name="lighting_preset" id="input_lighting_preset" value="{{ old('lighting_preset', 'bright') }}">
                    <input type="hidden" name="room_layout"     id="input_room_layout"     value="{{ old('room_layout', 'square') }}">
                    <input type="hidden" name="venue_template_id" id="input_venue_template_id" value="{{ old('venue_template_id', '') }}">

                    {{-- Venue Picker --}}
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-200 mb-3">Choose Your Venue *</label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3" id="venue-cards">
                            @foreach($venueTemplates as $venue)
                                @php
                                    $accessible = $venue->isAccessibleBy(auth()->user());
                                    $isSelected = old('venue_template_id', $venueTemplates->firstWhere('plan_required', 'free')?->id) == $venue->id;
                                @endphp
                                <div class="venue-card cursor-pointer relative {{ !$accessible ? 'opacity-60' : '' }}"
                                     data-venue-id="{{ $venue->id }}"
                                     data-wall="{{ $venue->default_settings['wall_texture'] }}"
                                     data-floor="{{ $venue->default_settings['floor_material'] }}"
                                     data-frame="{{ $venue->default_settings['frame_style'] }}"
                                     data-lighting="{{ $venue->default_settings['lighting_preset'] }}"
                                     data-layout="{{ $venue->default_settings['room_layout'] }}"
                                     data-accessible="{{ $accessible ? 'true' : 'false' }}">
                                    @php $cardBorder = $isSelected ? 'border-purple-500 bg-purple-900/20' : 'border-gray-600'; @endphp
<div class="venue-card-inner border-2 {{ $cardBorder }} rounded-lg p-3 text-center transition-all hover:border-purple-400 h-full flex flex-col items-center">
                                        {{-- Icon --}}
                                        @php
                                            $iconBg = match($venue->slug) {
                                                'white-cube'       => 'bg-gray-100/10',
                                                'industrial-loft'  => 'bg-orange-900/30',
                                                'dark-museum'      => 'bg-gray-900/60',
                                                'zen-gallery'      => 'bg-green-900/30',
                                                'luxury-penthouse' => 'bg-amber-900/30',
                                                'cyber-gallery'    => 'bg-blue-900/30',
                                                'sculpture-garden' => 'bg-emerald-900/30',
                                                default            => 'bg-purple-900/30',
                                            };
                                        @endphp
                                        <div class="w-10 h-10 rounded-lg mb-2 flex items-center justify-center text-xl {{ $iconBg }}">
                                            {{ $venue->slug === 'white-cube'       ? '⬜' :
                                               ($venue->slug === 'industrial-loft'  ? '🏭' :
                                               ($venue->slug === 'dark-museum'      ? '🏛️' :
                                               ($venue->slug === 'zen-gallery'      ? '🎋' :
                                               ($venue->slug === 'luxury-penthouse' ? '🏙️' :
                                               ($venue->slug === 'cyber-gallery'    ? '🌐' :
                                               ($venue->slug === 'sculpture-garden' ? '🌿' :
                                                '✨')))))))}}
                                        </div>
                                        <div class="text-sm font-medium text-gray-200 leading-tight">{{ $venue->name }}</div>
                                        <div class="text-xs text-gray-500 mt-1 leading-tight">{{ $venue->capacityLabel() }}</div>
                                        @if(!$accessible)
                                            <span class="mt-2 text-xs bg-purple-600 text-white px-2 py-0.5 rounded-full">
                                                {{ ucfirst($venue->plan_required) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        {{-- Selected venue description --}}
                        <p class="text-xs text-gray-400 mt-2 min-h-[1.5rem]" id="venue-description"></p>
                    </div>

                    {{-- Advanced settings: collapsible, open by default for Pro users --}}
                    @php $advOpen = Auth::user()->isPro() ? 'true' : 'false'; @endphp
                    <div x-data="{ open: {{ $advOpen }} }" class="mt-6">
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
// Venue descriptions (matches slug)
const venueDescriptions = {
    'white-cube':       'Minimal contemporary exhibition space. The professional standard.',
    'industrial-loft':  'Concrete, steel and large open spaces. Urban contemporary feel.',
    'dark-museum':      'Dramatic lighting with black walls. Premium artwork presentation.',
    'zen-gallery':      'Minimal architecture with natural materials. Calm and focused.',
    'luxury-penthouse': 'High-end collector experience with city views.',
    'cyber-gallery':    'Futuristic neon exhibition space for digital creators.',
    'sculpture-garden': 'Open-air exhibition environment for large installations.',
    'infinite-void':    'Floating artworks in an endless environment. No limits.',
};

function selectVenue(card) {
    const accessible = card.dataset.accessible === 'true';
    if (!accessible) {
        window.location.href = '/pricing';
        return;
    }

    // Deselect all
    document.querySelectorAll('.venue-card-inner').forEach(el => {
        el.classList.remove('border-purple-500', 'bg-purple-900/20');
        el.classList.add('border-gray-600');
    });

    // Select this one
    const inner = card.querySelector('.venue-card-inner');
    inner.classList.remove('border-gray-600');
    inner.classList.add('border-purple-500', 'bg-purple-900/20');

    // Populate hidden inputs
    document.getElementById('input_wall_texture').value    = card.dataset.wall;
    document.getElementById('input_floor_material').value  = card.dataset.floor;
    document.getElementById('input_frame_style').value     = card.dataset.frame;
    document.getElementById('input_lighting_preset').value = card.dataset.lighting;
    document.getElementById('input_room_layout').value     = card.dataset.layout;
    document.getElementById('input_venue_template_id').value = card.dataset.venueId;

    // Update description
    const slugMap = @json($venueTemplates->pluck('slug', 'id'));
    const venueSlug = slugMap[card.dataset.venueId];
    document.getElementById('venue-description').textContent = venueDescriptions[venueSlug] || '';
}

document.querySelectorAll('.venue-card').forEach(card => {
    card.addEventListener('click', () => selectVenue(card));
});

// Auto-select free venue on load (or restored old value)
const preselectedId = document.getElementById('input_venue_template_id').value;
if (preselectedId) {
    const preselected = document.querySelector(`.venue-card[data-venue-id="${preselectedId}"]`);
    if (preselected) selectVenue(preselected);
} else {
    const firstFree = document.querySelector('.venue-card[data-accessible="true"]');
    if (firstFree) selectVenue(firstFree);
}

// Submit spinner
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