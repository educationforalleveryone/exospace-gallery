<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">
            Edit Gallery: {{ $gallery->title }}
        </h2>
    </x-slot>

    <!-- Dropzone CSS -->
    <link rel="stylesheet" href="https://unpkg.com/dropzone@5/dist/min/dropzone.min.css" type="text/css" />
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

    <!-- Custom Premium Styles -->
    <style>
        /* Dropzone Drag Hover State */
        .dropzone.dz-drag-hover {
            border-color: #a855f7 !important;
            background: rgba(168, 85, 247, 0.05) !important;
        }

        /* Custom scrollbar for dark theme */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #1f2937;
        }
        ::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #6b7280;
        }

        /* Smooth transitions for cards */
        .gallery-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Custom checkbox styling */
        .custom-checkbox:checked {
            background-color: #9333ea;
            border-color: #9333ea;
        }

        /* Toast notifications */
        .toast-container {
            position: fixed;
            top: 1.25rem;
            right: 1.25rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            pointer-events: none;
        }
        .toast-item {
            pointer-events: auto;
            padding: 0.75rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.4);
            animation: toast-in 0.3s ease-out, toast-out 0.3s ease-in forwards;
            animation-delay: 0s, 2.7s;
            max-width: 360px;
        }
        .toast-item.success {
            background: rgba(16, 185, 129, 0.9);
            color: #fff;
            border: 1px solid rgba(52, 211, 153, 0.4);
        }
        .toast-item.error {
            background: rgba(239, 68, 68, 0.9);
            color: #fff;
            border: 1px solid rgba(248, 113, 113, 0.4);
        }
        .toast-item.info {
            background: rgba(99, 102, 241, 0.9);
            color: #fff;
            border: 1px solid rgba(129, 140, 248, 0.4);
        }
        @keyframes toast-in {
            from { opacity: 0; transform: translateX(100%) scale(0.95); }
            to { opacity: 1; transform: translateX(0) scale(1); }
        }
        @keyframes toast-out {
            from { opacity: 1; transform: translateX(0) scale(1); }
            to { opacity: 0; transform: translateX(100%) scale(0.95); }
        }
    </style>

    <!-- Toast container (rendered immediately) -->
    <div id="toast-container" class="toast-container"></div>

    <div class="py-12 bg-gray-900 min-h-screen">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <!-- 1. Gallery Settings -->
            <div class="bg-gray-800 border border-gray-700 shadow-lg sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-100 mb-4">Gallery Settings</h3>

                <form action="{{ route('admin.galleries.update', $gallery) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Title -->
                        <div class="mb-4 md:col-span-2">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Title</label>
                            <input type="text" name="title" value="{{ old('title', $gallery->title) }}" required
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors">
                        </div>

                        <!-- Description -->
                        <div class="mb-4 md:col-span-2">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Description</label>
                            <textarea name="description" rows="3"
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors">{{ old('description', $gallery->description) }}</textarea>
                        </div>

                        </div>

                    {{-- Hidden fields — populated by venue picker OR advanced overrides --}}
                    <input type="hidden" name="wall_texture"      id="edit_wall_texture"      value="{{ old('wall_texture', $gallery->wall_texture) }}">
                    <input type="hidden" name="floor_material"    id="edit_floor_material"    value="{{ old('floor_material', $gallery->floor_material) }}">
                    <input type="hidden" name="frame_style"       id="edit_frame_style"       value="{{ old('frame_style', $gallery->frame_style) }}">
                    <input type="hidden" name="lighting_preset"   id="edit_lighting_preset"   value="{{ old('lighting_preset', $gallery->lighting_preset) }}">
                    <input type="hidden" name="room_layout"       id="edit_room_layout"       value="{{ old('room_layout', $gallery->room_layout ?? 'square') }}">
                    <input type="hidden" name="venue_template_id" id="edit_venue_template_id" value="{{ old('venue_template_id', $gallery->venue_template_id) }}">

                    {{-- ── Venue Picker ──────────────────────────── --}}
                    <div class="mb-5">
                        <label class="block text-sm font-medium text-gray-400 mb-3">Venue</label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3" id="edit-venue-cards">
                            @foreach($venueTemplates as $venue)
                                @php
                                    $accessible = $venue->isAccessibleBy(auth()->user());
                                    $isSelected = $gallery->venue_template_id == $venue->id;
                                @endphp
                                <div class="edit-venue-card cursor-pointer"
                                     data-venue-id="{{ $venue->id }}"
                                     data-wall="{{ $venue->default_settings['wall_texture'] }}"
                                     data-floor="{{ $venue->default_settings['floor_material'] }}"
                                     data-frame="{{ $venue->default_settings['frame_style'] }}"
                                     data-lighting="{{ $venue->default_settings['lighting_preset'] }}"
                                     data-layout="{{ $venue->default_settings['room_layout'] }}"
                                     data-accessible="{{ $accessible ? 'true' : 'false' }}">
                                    @php $editCardBorder = $isSelected ? 'border-purple-500 bg-purple-900/20' : 'border-gray-600'; @endphp
<div class="edit-venue-card-inner border-2 {{ $editCardBorder }} {{ !$accessible ? 'opacity-50' : '' }} rounded-lg p-3 text-center transition-all hover:border-purple-400 h-full flex flex-col items-center">
                                        <div class="text-xl mb-1">
                                            @php
                                                $editVenueIcon = match($venue->slug) {
                                                    'white-cube'       => '⬜',
                                                    'industrial-loft'  => '🏭',
                                                    'dark-museum'      => '🏛️',
                                                    'zen-gallery'      => '🎋',
                                                    'luxury-penthouse' => '🏙️',
                                                    'cyber-gallery'    => '🌐',
                                                    'sculpture-garden' => '🌿',
                                                    default            => '✨',
                                                };
                                            @endphp
                                            {{ $editVenueIcon }}
                                        </div>
                                        <div class="text-xs font-medium text-gray-200 leading-tight">{{ $venue->name }}</div>
                                        @if(!$accessible)
                                            <span class="mt-1 text-xs bg-purple-600 text-white px-1.5 py-0.5 rounded-full">{{ ucfirst($venue->plan_required) }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <p class="text-xs text-gray-500 mt-2" id="edit-venue-description"></p>
                    </div>

                    {{-- ── Advanced overrides (collapsed) ───────────── --}}
                    <div x-data="{ open: false }" class="mb-4">
                        <button type="button" @click="open = !open"
                                class="flex items-center gap-2 text-xs text-gray-500 hover:text-gray-300 transition border-t border-gray-700/60 pt-3 pb-1 w-full text-left">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                            Override venue materials
                            <svg class="w-3.5 h-3.5 ml-auto transition-transform duration-200" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="open" x-transition class="grid grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Wall</label>
                                <select id="adv_wall" class="block w-full rounded bg-gray-700 border-gray-600 text-gray-100 text-sm focus:border-purple-500">
                                    @foreach(['white'=>'White','concrete'=>'Concrete','brick'=>'Brick','wood'=>'Wood'] as $v=>$l)
                                        <option value="{{ $v }}" {{ $gallery->wall_texture == $v ? 'selected' : '' }}>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Floor</label>
                                <select id="adv_floor" class="block w-full rounded bg-gray-700 border-gray-600 text-gray-100 text-sm focus:border-purple-500">
                                    @foreach(['wood'=>'Wood','marble'=>'Marble','concrete'=>'Concrete'] as $v=>$l)
                                        <option value="{{ $v }}" {{ $gallery->floor_material == $v ? 'selected' : '' }}>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Frame</label>
                                <select id="adv_frame" class="block w-full rounded bg-gray-700 border-gray-600 text-gray-100 text-sm focus:border-purple-500">
                                    @foreach(['modern'=>'Modern (Black)','classic'=>'Classic (Gold)','minimal'=>'Minimal'] as $v=>$l)
                                        <option value="{{ $v }}" {{ $gallery->frame_style == $v ? 'selected' : '' }}>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Lighting</label>
                                <select id="adv_lighting" class="block w-full rounded bg-gray-700 border-gray-600 text-gray-100 text-sm focus:border-purple-500">
                                    @foreach(['bright'=>'Bright','moody'=>'Moody','dramatic'=>'Dramatic'] as $v=>$l)
                                        <option value="{{ $v }}" {{ $gallery->lighting_preset == $v ? 'selected' : '' }}>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- CHANGE #1: AJAX Audio Upload Section -->
                    <!-- Background Music (Pro Feature) -->
                    <div class="mb-6 mt-6 p-6 bg-gray-900/50 rounded-lg border border-gray-600">
                        <label class="block text-sm font-medium text-gray-300 mb-3">
                            🎵 Background Music
                            @if(!auth()->user()->isPro())
                                <span class="text-xs bg-purple-600 text-white px-2 py-0.5 rounded-full ml-2">Pro Only</span>
                            @endif
                        </label>

                        @if(auth()->user()->isPro())
                            <!-- ✅ AJAX Upload Container -->
                            <div class="space-y-3">
                                <!-- Current Audio Preview -->
                                <div id="audio-preview-container" @if($gallery->audio_path) @else style="display:none;" @endif class="bg-gray-700 rounded-lg p-3 flex items-center justify-between mb-3">
                                    <div class="flex items-center">
                                        <svg class="w-5 h-5 text-purple-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                        </svg>
                                        <span id="audio-filename" class="text-sm text-gray-300">
                                            @if($gallery->audio_path)
                                                {{ basename($gallery->audio_path) }}
                                            @else
                                                No audio uploaded
                                            @endif
                                        </span>
                                    </div>
                                    <audio id="audio-player" controls class="h-8">
                                        @if($gallery->audio_path)
                                            <source src="{{ asset('storage/' . $gallery->audio_path) }}" type="audio/mpeg">
                                        @endif
                                    </audio>
                                </div>

                                <!-- ✅ Upload Input -->
                                <div class="relative">
                                    <input type="file" id="audio-upload-input" accept=".mp3,.wav,.m4a"
                                        onchange="uploadAudioFile(this)"
                                        class="block w-full text-sm text-gray-300
                                            file:mr-4 file:py-2 file:px-4
                                            file:rounded-lg file:border-0
                                            file:text-sm file:font-semibold
                                            file:bg-purple-600 file:text-white
                                            hover:file:bg-purple-700
                                            cursor-pointer">

                                    <!-- ✅ Progress Bar (Hidden by default) -->
                                    <div id="audio-upload-progress" style="display:none;" class="mt-2">
                                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                                            <span id="audio-progress-text">Uploading...</span>
                                            <span id="audio-progress-percent">0%</span>
                                        </div>
                                        <div class="w-full h-2 bg-gray-700 rounded-full overflow-hidden">
                                            <div id="audio-progress-bar" class="h-full bg-gradient-to-r from-purple-500 to-indigo-500 transition-all duration-300" style="width: 0%"></div>
                                        </div>
                                    </div>

                                    <!-- ✅ Success Message (Hidden by default) -->
                                    <div id="audio-upload-success" style="display:none;" class="mt-2 p-2 bg-green-900/50 border border-green-700 rounded text-green-300 text-sm">
                                        ✅ <span id="audio-success-message">Audio uploaded successfully!</span>
                                    </div>

                                    <!-- ✅ Error Message (Hidden by default) -->
                                    <div id="audio-upload-error" style="display:none;" class="mt-2 p-3 bg-red-950/50 border border-red-700/60 rounded-lg flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2 text-red-300 text-sm">
                                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <span id="audio-error-message">Upload failed</span>
                                        </div>
                                        <button type="button" onclick="document.getElementById('audio-upload-input').click()"
                                                class="flex-shrink-0 text-xs bg-red-900/50 hover:bg-red-900 text-red-300 border border-red-700/50 px-2.5 py-1 rounded-lg transition">
                                            Retry
                                        </button>
                                    </div>
                                </div>

                                <p class="text-xs text-gray-500 mt-2">MP3, WAV, or M4A • Max 10MB • Upload happens instantly</p>
                            </div>
                        @else
                            <!-- Show locked state for Free users (inline design) -->
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

                    <!-- CHANGE #2: AJAX Logo Upload Section -->
                    <!-- Custom Branding (Studio Feature) -->
                    <div class="mb-6 mt-6 p-6 bg-gray-900/50 rounded-lg border border-gray-600">
                        <label class="block text-sm font-medium text-gray-300 mb-3">
                            🎨 Custom Logo
                            @if(auth()->user()->plan !== 'studio')
                                <span class="text-xs bg-purple-600 text-white px-2 py-0.5 rounded-full ml-2">Studio Only</span>
                            @endif
                        </label>

                        @if(auth()->user()->plan === 'studio')
                            <!-- ✅ AJAX Upload Container -->
                            <div class="space-y-3">
                                <!-- Current Logo Preview -->
                                <div id="logo-preview-container" @if($gallery->custom_logo_path) @else style="display:none;" @endif class="bg-gray-700 rounded-lg p-3 mb-3 flex items-center justify-center">
                                    <img id="logo-preview-image"
                                         src="{{ $gallery->custom_logo_path ? asset('storage/' . $gallery->custom_logo_path) : '' }}"
                                         alt="Custom Logo"
                                         class="max-h-20 object-contain">
                                </div>

                                <!-- ✅ Upload Input -->
                                <div class="relative">
                                    <input type="file" id="logo-upload-input" accept=".png,.svg,.jpg,.jpeg"
                                        onchange="uploadLogoFile(this)"
                                        class="block w-full text-sm text-gray-300
                                            file:mr-4 file:py-2 file:px-4
                                            file:rounded-lg file:border-0
                                            file:text-sm file:font-semibold
                                            file:bg-purple-600 file:text-white
                                            hover:file:bg-purple-700
                                            cursor-pointer">

                                    <!-- ✅ Progress Bar (Hidden by default) -->
                                    <div id="logo-upload-progress" style="display:none;" class="mt-2">
                                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                                            <span id="logo-progress-text">Uploading...</span>
                                            <span id="logo-progress-percent">0%</span>
                                        </div>
                                        <div class="w-full h-2 bg-gray-700 rounded-full overflow-hidden">
                                            <div id="logo-progress-bar" class="h-full bg-gradient-to-r from-purple-500 to-indigo-500 transition-all duration-300" style="width: 0%"></div>
                                        </div>
                                    </div>

                                    <!-- ✅ Success Message (Hidden by default) -->
                                    <div id="logo-upload-success" style="display:none;" class="mt-2 p-2 bg-green-900/50 border border-green-700 rounded text-green-300 text-sm">
                                        ✅ <span id="logo-success-message">Logo uploaded successfully!</span>
                                    </div>

                                    <!-- ✅ Error Message (Hidden by default) -->
                                    <div id="logo-upload-error" style="display:none;" class="mt-2 p-3 bg-red-950/50 border border-red-700/60 rounded-lg flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2 text-red-300 text-sm">
                                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <span id="logo-error-message">Upload failed</span>
                                        </div>
                                        <button type="button" onclick="document.getElementById('logo-upload-input').click()"
                                                class="flex-shrink-0 text-xs bg-red-900/50 hover:bg-red-900 text-red-300 border border-red-700/50 px-2.5 py-1 rounded-lg transition">
                                            Retry
                                        </button>
                                    </div>
                                </div>

                                <p class="text-xs text-gray-500 mt-2">PNG, SVG, JPG • Max 2MB • Transparent background recommended • Upload happens instantly</p>
                            </div>
                        @else
                            <!-- Locked State for Free/Pro Users -->
                            <div class="relative">
                                <div class="bg-gray-800/30 rounded-lg p-6 border-2 border-dashed border-gray-700 text-center opacity-60">
                                    <svg class="w-16 h-16 text-gray-600 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                    <p class="text-gray-500 text-sm mb-4">Replace "Exospace" branding with your own logo</p>
                                </div>

                                <div class="absolute inset-0 flex items-center justify-center">
                                    <a href="/pricing"
                                       class="bg-gradient-to-r from-orange-600 to-red-600 hover:from-orange-700 hover:to-red-700 text-white font-bold px-8 py-3 rounded-lg shadow-lg transform hover:scale-105 transition-all">
                                        <div class="flex items-center gap-2">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                            </svg>
                                            <span>Upgrade to Studio</span>
                                        </div>
                                    </a>
                                </div>
                            </div>

                            <div class="mt-4 bg-orange-900/20 border border-orange-700/30 rounded-lg p-4">
                                <h4 class="text-orange-300 font-semibold text-sm mb-2">🌟 Studio Plan Benefits</h4>
                                <ul class="text-xs text-orange-300/80 space-y-1 ml-4">
                                    <li>• White-label your galleries with custom branding</li>
                                    <li>• Remove "Exospace" watermark completely</li>
                                    <li>• Professional presentation for clients</li>
                                    <li>• Perfect for agencies and professional artists</li>
                                </ul>
                            </div>
                        @endif
                    </div>

                    <!-- Exhibition Scheduling (Pro & Studio) -->
                    <div class="mb-6 pt-5 border-t border-gray-700">
                        <div class="flex items-center gap-2 mb-1">
                            <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <label class="block text-sm font-medium text-gray-300">Exhibition Schedule</label>
                            <span class="text-xs bg-purple-900/50 text-purple-300 border border-purple-700/50 px-2 py-0.5 rounded-full">Pro</span>
                        </div>
                        <p class="text-xs text-gray-500 mb-4">Set an opening date and optional closing date. Visitors will see a countdown before opening and a "Closed" page after. Leave blank for always-open.</p>

                        @if(Auth::user()->isPro())
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-400 mb-1">Opens At</label>
                                    <input type="datetime-local" name="opens_at"
                                        value="{{ $gallery->opens_at ? $gallery->opens_at->format('Y-m-d\TH:i') : old('opens_at') }}"
                                        class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors text-sm">
                                    <p class="text-xs text-gray-500 mt-1">Your local time. Leave blank to open immediately.</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-400 mb-1">Closes At</label>
                                    <input type="datetime-local" name="closes_at"
                                        value="{{ $gallery->closes_at ? $gallery->closes_at->format('Y-m-d\TH:i') : old('closes_at') }}"
                                        class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors text-sm">
                                    <p class="text-xs text-gray-500 mt-1">Optional. Leave blank for no end date.</p>
                                </div>
                            </div>

                            @if($gallery->isScheduled())
                                <div class="mt-3 p-3 rounded-lg text-sm
                                    @php
                                        $scheduleClass = $gallery->hasNotOpenedYet()
                                            ? 'bg-blue-900/30 border border-blue-700/40 text-blue-300'
                                            : ($gallery->hasClosed()
                                                ? 'bg-red-900/30 border border-red-700/40 text-red-300'
                                                : 'bg-green-900/30 border border-green-700/40 text-green-300');
                                    @endphp
                                    {{ $scheduleClass }}">
                                    @if($gallery->hasNotOpenedYet())
                                        🕐 <strong>Scheduled</strong> — Opens {{ $gallery->opens_at->diffForHumans() }}
                                        ({{ $gallery->opens_at->format('M j, Y \a\t g:i A') }})
                                    @elseif($gallery->hasClosed())
                                        🔒 <strong>Closed</strong> — Exhibition ended {{ $gallery->closes_at->diffForHumans() }}
                                    @else
                                        ✅ <strong>Open</strong> —
                                        {{ $gallery->closes_at ? 'Closes ' . $gallery->closes_at->diffForHumans() . ' (' . $gallery->closes_at->format('M j, Y \a\t g:i A') . ')' : 'No closing date set' }}
                                    @endif
                                </div>
                            @endif
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

                    <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-700">
                        <a href="{{ route('admin.galleries.index') }}" class="bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white px-4 py-2 rounded-lg transition-colors">
                            Cancel
                        </a>
                        <button type="submit" id="update-settings-btn"
                                class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white px-6 py-2 rounded-lg font-medium shadow-lg shadow-purple-900/30 transition-all inline-flex items-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed">
                            <svg id="update-spinner" class="hidden animate-spin w-4 h-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                            <span id="update-label">Update Settings</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- 2. Image Upload Area -->
            <div class="bg-gray-800 border border-gray-700 shadow-lg sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-100 mb-4">Upload Artworks</h3>

                @php
                    $imgCount = $gallery->images()->count();
                    $imgMax   = Auth::user()->max_images;
                    $imgPct   = $imgMax > 0 ? min(($imgCount / $imgMax) * 100, 100) : 0;
                    $imgNear  = $imgPct >= 80;
                    $imgFull  = $imgCount >= $imgMax;
                @endphp

                @if($imgNear || $imgFull)
                @php $imgFullClass = $imgFull ? 'bg-red-950/40 border-red-700/50' : 'bg-amber-950/40 border-amber-600/50'; @endphp
<div class="mb-4 flex items-center gap-3 {{ $imgFullClass }} border rounded-xl px-4 py-3">
                    <svg class="w-4 h-4 {{ $imgFull ? 'text-red-400' : 'text-amber-400' }} flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <p class="flex-1 text-sm {{ $imgFull ? 'text-red-200' : 'text-amber-200' }}">
                        @if($imgFull)
                            This gallery has reached its {{ $imgMax }}-image limit.
                            @if(!Auth::user()->isPro()) Upgrade to Pro for 50 images per gallery. @endif
                        @else
                            @php $slotsLeft = $imgMax - $imgCount; @endphp
                            {{ $imgCount }} of {{ $imgMax }} images used — {{ $slotsLeft }} slot{{ $slotsLeft === 1 ? '' : 's' }} remaining.
                        @endif
                    </p>
                    @if(!Auth::user()->isPro())
                    <a href="/pricing" class="flex-shrink-0 text-xs font-semibold {{ $imgFull ? 'bg-red-600 hover:bg-red-500' : 'bg-amber-600 hover:bg-amber-500' }} text-white px-3 py-1.5 rounded-lg transition whitespace-nowrap">
                        Upgrade
                    </a>
                    @endif
                </div>
                @endif

                @if($imgFull)
                <div class="border-2 border-dashed border-gray-700 rounded-lg bg-gray-900/30 px-6 py-10 text-center">
                    <svg class="w-10 h-10 text-gray-600 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    <p class="text-gray-500 text-sm">Upload slot full — {{ $imgMax }} of {{ $imgMax }} images used.</p>
                    @if(!Auth::user()->isPro())
                        <a href="/pricing" class="text-xs text-purple-400 hover:text-purple-300 mt-2 inline-block underline underline-offset-2 transition">Upgrade for more image slots →</a>
                    @endif
                </div>
                @else
                <form action="{{ route('admin.images.store', $gallery) }}"
                      class="dropzone border-dashed border-2 border-gray-600 rounded-lg bg-gray-750/50 hover:bg-gray-750 transition-all duration-300 cursor-pointer"
                      id="image-upload-dropzone">
                    @csrf
                </form>
                @endif
            </div>

            <!-- 3. Existing Images Grid -->
            <div class="bg-gray-800 border border-gray-700 shadow-lg sm:rounded-lg p-6">
                <!-- 3A: Updated Header with Bulk Action Button and Select All -->
                <div class="flex justify-between items-center mb-6">
                    <div class="flex items-center gap-4">
                        <h3 class="text-lg font-medium text-gray-100">
                            Current Images ({{ $gallery->images->count() }})
                        </h3>
                        @if($gallery->images->count() > 0)
                            <label class="flex items-center gap-2 text-sm text-gray-400 cursor-pointer hover:text-purple-400 transition-colors select-none">
                                <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll()"
                                       class="w-4 h-4 rounded border-gray-600 bg-gray-700 text-purple-600 focus:ring-2 focus:ring-purple-500 focus:ring-offset-0 focus:ring-offset-gray-800">
                                <span>Select All</span>
                            </label>
                        @endif
                    </div>
                    <button id="bulk-delete-btn" onclick="bulkDelete()" style="display: none;"
                            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-semibold transition-all shadow-lg shadow-red-900/20 flex items-center gap-2 transform hover:scale-[1.02]">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        Delete Selected (<span id="selected-count">0</span>)
                    </button>
                </div>

                @if($gallery->images->count() > 0)
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-5" id="gallery-grid">
                        @foreach($gallery->images as $image)
                            <div class="gallery-card relative group bg-gray-900 border border-gray-700 rounded-lg overflow-hidden hover:border-purple-500 hover:-translate-y-1 hover:shadow-xl hover:shadow-purple-900/20" id="image-{{ $image->id }}" data-id="{{ $image->id }}">

                                <!-- 3B: Selection Checkbox -->
                                <div class="absolute top-3 left-3 z-20 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                                    <input type="checkbox" value="{{ $image->id }}"
                                           onchange="updateSelection()"
                                           class="image-checkbox w-5 h-5 rounded border-gray-600 bg-gray-700 text-purple-600 shadow-lg focus:ring-2 focus:ring-purple-500 cursor-pointer transition-all">
                                </div>

                                <!-- Image: Enforced Aspect Ratio (Square) -->
                                <div class="aspect-square w-full bg-gray-950 overflow-hidden">
                                    <img src="{{ asset($image->path) }}"
                                         alt="{{ $image->original_name }}"
                                         class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                                </div>

                                <!-- Delete Button: Pro Style -->
                                <button onclick="deleteImage({{ $image->id }})"
                                        type="button"
                                        class="absolute top-3 right-3 bg-red-600/80 hover:bg-red-600 text-white w-8 h-8 flex items-center justify-center rounded-full shadow-lg transition-all duration-200 z-10 opacity-0 group-hover:opacity-100 transform scale-90 group-hover:scale-100"
                                        title="Delete Image">
                                    <span class="text-lg font-bold leading-none">&times;</span>
                                </button>

                                <!-- Caption -->
                                <div class="p-3 bg-gray-900 border-t border-gray-800">
                                    <p class="text-xs text-gray-500 truncate text-center font-medium">
                                        {{ $image->original_name }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-gray-500 text-center py-12 bg-gray-900/50 rounded-lg border-2 border-dashed border-gray-700">
                        <svg class="w-12 h-12 mx-auto mb-3 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <p class="text-gray-400">No images yet. Upload your first artwork above!</p>
                    </div>
                @endif
            </div>

        </div>
    </div>

    <!-- Dropzone & Scripts -->
    <script src="https://unpkg.com/dropzone@5/dist/min/dropzone.min.js"></script>
    <script>
        // ─── Toast Notification System ───────────────────────────
        function toast(message, type = 'info') {
            const container = document.getElementById('toast-container');
            const el = document.createElement('div');
            el.className = `toast-item ${type}`;
            el.textContent = message;
            container.appendChild(el);
            setTimeout(() => el.remove(), 3200);
        }

        // ─── Dropzone Config ────────────────────────────────────
        Dropzone.options.imageUploadDropzone = {
            paramName: "file",
            maxFilesize: 10,
            maxFiles: 100,
            parallelUploads: 2,
            timeout: 180000,
            acceptedFiles: ".jpeg,.jpg,.png,.webp",
            dictDefaultMessage: "📸 <span class='text-purple-400 font-bold text-lg'>Drag your artwork here</span> or <span class='underline cursor-pointer'>browse</span><br><span class='text-xs text-gray-500 mt-2 block'>Supports JPG, PNG, WEBP (Max 10MB)</span>",
            addRemoveLinks: true,
            uploadMultiple: false,
            autoProcessQueue: true,

            init: function() {
                let uploadedCount = 0;
                let totalFiles = 0;
                let hasErrors = false;
                let failedFiles = [];

                this.on("addedfiles", function(files) {
                    totalFiles = files.length;
                    uploadedCount = 0;
                    hasErrors = false;
                    failedFiles = [];
                    console.log(`📤 Starting upload of ${totalFiles} images...`);
                });

                this.on("success", function(file, response) {
                    if(response.success) {
                        uploadedCount++;
                        console.log(`✅ Uploaded ${uploadedCount}/${totalFiles}: ${file.name}`);
                    }
                });

                this.on("error", function(file, errorMessage, xhr) {
                    hasErrors = true;

                    let cleanError = 'Unknown error';

                    if (typeof errorMessage === 'object' && errorMessage.error) {
                        cleanError = errorMessage.error;
                    } else if (typeof errorMessage === 'string') {
                        cleanError = errorMessage.includes('failed to upload')
                            ? 'Upload failed - check file size/format'
                            : errorMessage;
                    } else if (xhr) {
                        if (xhr.status === 422) {
                            cleanError = 'Validation failed (size/format issue)';
                        } else if (xhr.status === 413) {
                            cleanError = 'File too large (server limit)';
                        } else if (xhr.status === 500) {
                            cleanError = 'Server error during processing';
                        }
                    }

                    failedFiles.push({
                        name: file.name,
                        error: cleanError
                    });

                    console.error(`❌ Upload failed for ${file.name}:`, cleanError);
                });

                this.on("queuecomplete", function() {
                    console.log(`🎉 Queue complete! Uploaded: ${uploadedCount}/${totalFiles}`);

                    if (failedFiles.length > 0) {
                        const errHtml = failedFiles.map(f => `<li class="text-red-300 text-xs">• <strong>${f.name}</strong>: ${f.error}</li>`).join('');
                        const banner = document.createElement('div');
                        banner.className = 'mt-3 p-3 bg-red-950/60 border border-red-700/60 rounded-lg text-sm';
                        banner.innerHTML = `<p class="text-red-300 font-semibold mb-1.5">⚠ ${failedFiles.length} file${failedFiles.length > 1 ? 's' : ''} failed to upload:</p><ul class="space-y-0.5">${errHtml}</ul><p class="text-red-400/70 text-xs mt-2">Common fixes: reduce file size below 10MB, use JPG/PNG/WEBP format.</p>`;
                        document.getElementById('image-upload-dropzone').after(banner);
                        setTimeout(() => banner.remove(), 12000);
                    }

                    if (uploadedCount > 0) {
                        toast(`${uploadedCount} image${uploadedCount > 1 ? 's' : ''} uploaded`, 'success');
                        setTimeout(() => location.reload(), 1200);
                    }
                });
            }
        };

        // ─── Delete Single Image (inline confirm overlay) ───────
        function deleteImage(id) {
            const el = document.getElementById(`image-${id}`);
            // Inline confirm overlay on the card itself
            const overlay = document.createElement('div');
            overlay.className = 'absolute inset-0 bg-gray-900/90 backdrop-blur-sm z-30 flex flex-col items-center justify-center gap-2 rounded-lg';
            overlay.innerHTML = `
                <p class="text-xs text-gray-300 text-center px-2">Delete permanently?</p>
                <div class="flex gap-2">
                    <button onclick="confirmDeleteImage(${id})" class="bg-red-600 hover:bg-red-500 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">Delete</button>
                    <button onclick="this.closest('.absolute').remove()" class="bg-gray-700 hover:bg-gray-600 text-gray-300 text-xs font-medium px-3 py-1.5 rounded-lg transition">Cancel</button>
                </div>`;
            el.style.position = 'relative';
            el.appendChild(overlay);
        }

        function confirmDeleteImage(id) {
            const el = document.getElementById(`image-${id}`);
            el.style.opacity = '0.5';
            fetch(`/admin/images/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    el.style.transform = 'scale(0.85)';
                    el.style.opacity = '0';
                    el.style.transition = 'all 0.2s';
                    setTimeout(() => { el.remove(); updateImageCount(); }, 220);
                    toast('Image deleted', 'success');
                } else {
                    el.style.opacity = '1';
                    toast('Could not delete — please try again', 'error');
                }
            })
            .catch(() => { el.style.opacity = '1'; toast('Network error — please try again', 'error'); });
        }

        function updateImageCount() {
            const count = document.querySelectorAll('.gallery-card').length;
            const header = document.querySelector('h3.text-lg.font-medium.text-gray-100');
            if (header) header.textContent = `Current Images (${count})`;
        }

        // ─── Select All / Selection State ──────────────────────
        function toggleSelectAll() {
            const selectAllCheckbox = document.getElementById('select-all-checkbox');
            const imageCheckboxes = document.querySelectorAll('.image-checkbox');

            imageCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
                const card = checkbox.closest('.gallery-card');
                if (selectAllCheckbox.checked) {
                    card.classList.add('ring-2', 'ring-purple-500', 'border-purple-500');
                } else {
                    card.classList.remove('ring-2', 'ring-purple-500', 'border-purple-500');
                }
            });

            updateSelection();
        }

        function updateSelection() {
            const checkboxes = document.querySelectorAll('.image-checkbox:checked');
            const allCheckboxes = document.querySelectorAll('.image-checkbox');
            const selectAllCheckbox = document.getElementById('select-all-checkbox');
            const btn = document.getElementById('bulk-delete-btn');
            const countSpan = document.getElementById('selected-count');

            allCheckboxes.forEach(cb => {
                const card = cb.closest('.gallery-card');
                if (cb.checked) {
                    card.classList.add('ring-2', 'ring-purple-500', 'border-purple-500');
                    cb.parentElement.style.opacity = '1';
                } else {
                    card.classList.remove('ring-2', 'ring-purple-500', 'border-purple-500');
                    cb.parentElement.style.opacity = '';
                }
            });

            if (selectAllCheckbox) {
                selectAllCheckbox.checked = checkboxes.length === allCheckboxes.length && allCheckboxes.length > 0;
                selectAllCheckbox.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
            }

            if (checkboxes.length > 0) {
                btn.style.display = 'flex';
                countSpan.textContent = checkboxes.length;
            } else {
                btn.style.display = 'none';
            }
        }

        // ─── Bulk Delete (inline confirmation bar) ─────────────
        function bulkDelete() {
            const checkboxes = document.querySelectorAll('.image-checkbox:checked');
            const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));

            if (ids.length === 0) return;

            // Remove any existing confirmation bar
            const existing = document.getElementById('bulk-confirm-bar');
            if (existing) { existing.remove(); }

            const bar = document.createElement('div');
            bar.id = 'bulk-confirm-bar';
            bar.className = 'mt-4 flex items-center gap-3 bg-red-950/60 border border-red-700/60 rounded-xl px-4 py-3';
            bar.innerHTML = `
                <svg class="w-4 h-4 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <p class="flex-1 text-sm text-red-200">Delete <strong>${ids.length} image${ids.length > 1 ? 's' : ''}</strong> permanently? This cannot be undone.</p>
                <button onclick="executeBulkDelete([${ids.join(',')}])" class="flex-shrink-0 bg-red-600 hover:bg-red-500 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">Confirm Delete</button>
                <button onclick="document.getElementById('bulk-confirm-bar').remove()" class="flex-shrink-0 text-xs text-gray-400 hover:text-gray-200 px-2 py-1.5 transition">Cancel</button>`;
            document.getElementById('bulk-delete-btn').after(bar);
        }

        function executeBulkDelete(ids) {
            const bar = document.getElementById('bulk-confirm-bar');
            if (bar) bar.remove();
            const btn = document.getElementById('bulk-delete-btn');
            const originalHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Deleting...';

            fetch('{{ route("admin.images.bulk_destroy") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                body: JSON.stringify({ ids })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    toast(`${data.deleted} image${data.deleted > 1 ? 's' : ''} deleted`, 'success');
                    location.reload();
                } else {
                    toast('Delete failed — please try again', 'error');
                    btn.disabled = false;
                    btn.innerHTML = originalHTML;
                }
            })
            .catch(() => {
                toast('Network error — please try again', 'error');
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            });
        }

        // ─── AJAX: Upload Audio File ───────────────────────────
        function uploadAudioFile(input) {
            const file = input.files[0];
            if (!file) return;

            document.getElementById('audio-upload-success').style.display = 'none';
            document.getElementById('audio-upload-error').style.display = 'none';

            const progressDiv = document.getElementById('audio-upload-progress');
            const progressBar = document.getElementById('audio-progress-bar');
            const progressPercent = document.getElementById('audio-progress-percent');
            const progressText = document.getElementById('audio-progress-text');

            progressDiv.style.display = 'block';
            progressBar.style.width = '0%';
            progressPercent.textContent = '0%';
            progressText.textContent = 'Uploading audio...';

            const formData = new FormData();
            formData.append('audio', file);
            formData.append('_token', '{{ csrf_token() }}');

            fetch('{{ route("admin.galleries.upload-audio", $gallery) }}', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                let progress = 0;
                const interval = setInterval(() => {
                    progress += 10;
                    progressBar.style.width = progress + '%';
                    progressPercent.textContent = progress + '%';

                    if (progress >= 100) {
                        clearInterval(interval);

                        if (data.success) {
                            progressDiv.style.display = 'none';
                            document.getElementById('audio-success-message').textContent = data.message;
                            document.getElementById('audio-upload-success').style.display = 'block';
                            document.getElementById('audio-preview-container').style.display = 'flex';
                            document.getElementById('audio-filename').textContent = data.filename;

                            const audioPlayer = document.getElementById('audio-player');
                            audioPlayer.innerHTML = `<source src="${data.audio_url}" type="audio/mpeg">`;
                            audioPlayer.load();

                            input.value = '';

                            setTimeout(() => {
                                document.getElementById('audio-upload-success').style.display = 'none';
                            }, 5000);
                        } else {
                            progressDiv.style.display = 'none';
                            document.getElementById('audio-error-message').textContent = data.message || 'Upload failed';
                            document.getElementById('audio-upload-error').style.display = 'block';
                        }
                    }
                }, 50);
            })
            .catch(error => {
                console.error('Upload error:', error);
                progressDiv.style.display = 'none';
                document.getElementById('audio-error-message').textContent = 'Network error. Please try again.';
                document.getElementById('audio-upload-error').style.display = 'block';
            });
        }

        // ─── AJAX: Upload Logo File ────────────────────────────
        function uploadLogoFile(input) {
            const file = input.files[0];
            if (!file) return;

            document.getElementById('logo-upload-success').style.display = 'none';
            document.getElementById('logo-upload-error').style.display = 'none';

            const progressDiv = document.getElementById('logo-upload-progress');
            const progressBar = document.getElementById('logo-progress-bar');
            const progressPercent = document.getElementById('logo-progress-percent');
            const progressText = document.getElementById('logo-progress-text');

            progressDiv.style.display = 'block';
            progressBar.style.width = '0%';
            progressPercent.textContent = '0%';
            progressText.textContent = 'Uploading logo...';

            const formData = new FormData();
            formData.append('custom_logo', file);
            formData.append('_token', '{{ csrf_token() }}');

            fetch('{{ route("admin.galleries.upload-logo", $gallery) }}', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                let progress = 0;
                const interval = setInterval(() => {
                    progress += 10;
                    progressBar.style.width = progress + '%';
                    progressPercent.textContent = progress + '%';

                    if (progress >= 100) {
                        clearInterval(interval);

                        if (data.success) {
                            progressDiv.style.display = 'none';
                            document.getElementById('logo-success-message').textContent = data.message;
                            document.getElementById('logo-upload-success').style.display = 'block';
                            document.getElementById('logo-preview-container').style.display = 'flex';
                            document.getElementById('logo-preview-image').src = data.logo_url;

                            input.value = '';

                            setTimeout(() => {
                                document.getElementById('logo-upload-success').style.display = 'none';
                            }, 5000);
                        } else {
                            progressDiv.style.display = 'none';
                            document.getElementById('logo-error-message').textContent = data.message || 'Upload failed';
                            document.getElementById('logo-upload-error').style.display = 'block';
                        }
                    }
                }, 50);
            })
            .catch(error => {
                console.error('Upload error:', error);
                progressDiv.style.display = 'none';
                document.getElementById('logo-error-message').textContent = 'Network error. Please try again.';
                document.getElementById('logo-upload-error').style.display = 'block';
            });
        }
        document.querySelector('form[action*="galleries"]').addEventListener('submit', function() {
            const btn = document.getElementById('update-settings-btn');
            document.getElementById('update-spinner').classList.remove('hidden');
            document.getElementById('update-label').textContent = 'Saving…';
            btn.disabled = true;
        });
        // Unsaved changes guard
        (function() {
            let dirty = false;
            const form = document.querySelector('form[action*="galleries"]');
            if (!form) return;
            form.querySelectorAll('input, select, textarea').forEach(el => {
                el.addEventListener('change', () => dirty = true);
                el.addEventListener('input', () => dirty = true);
            });
            form.addEventListener('submit', () => dirty = false);
            window.addEventListener('beforeunload', e => {
                if (dirty) { e.preventDefault(); e.returnValue = ''; }
            });
        })();
    </script>

    <!-- Reorder save bar -->
    <div id="reorder-save-bar">
        <svg class="w-4 h-4 text-purple-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
        <span>Order changed</span>
        <button class="save-btn" onclick="saveOrder()">Save Order</button>
        <button class="discard-btn" onclick="discardOrder()">Discard</button>
    </div>

    <script>
        // Warn on navigate if order changed but not saved
        window.addEventListener('beforeunload', e => {
            const reorderBar = document.getElementById('reorder-save-bar');
            if (reorderBar && 
                getComputedStyle(reorderBar).display !== 'none' &&
                reorderBar.style.display !== 'none') {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>

<script>
const editVenueDescriptions = {
    'white-cube':       'Minimal contemporary exhibition space.',
    'industrial-loft':  'Concrete, steel and large open spaces.',
    'dark-museum':      'Dramatic lighting with black walls.',
    'zen-gallery':      'Minimal architecture with natural materials.',
    'luxury-penthouse': 'High-end collector experience.',
    'cyber-gallery':    'Futuristic neon exhibition space.',
    'sculpture-garden': 'Open-air exhibition environment.',
    'infinite-void':    'Floating artworks in an endless environment.',
};
const editSlugMap = @json($venueTemplates->pluck('slug', 'id'));

function selectEditVenue(card) {
    if (card.dataset.accessible !== 'true') {
        window.location.href = '/pricing';
        return;
    }
    document.querySelectorAll('.edit-venue-card-inner').forEach(el => {
        el.classList.remove('border-purple-500', 'bg-purple-900/20');
        el.classList.add('border-gray-600');
    });
    card.querySelector('.edit-venue-card-inner').classList.remove('border-gray-600');
    card.querySelector('.edit-venue-card-inner').classList.add('border-purple-500', 'bg-purple-900/20');

    // Populate hidden fields from venue defaults
    document.getElementById('edit_wall_texture').value      = card.dataset.wall;
    document.getElementById('edit_floor_material').value    = card.dataset.floor;
    document.getElementById('edit_frame_style').value       = card.dataset.frame;
    document.getElementById('edit_lighting_preset').value   = card.dataset.lighting;
    document.getElementById('edit_room_layout').value       = card.dataset.layout;
    document.getElementById('edit_venue_template_id').value = card.dataset.venueId;

    // Sync advanced dropdowns to reflect new venue defaults
    const adv = { wall: 'adv_wall', floor: 'adv_floor', frame: 'adv_frame', lighting: 'adv_lighting' };
    if (document.getElementById('adv_wall')) {
        document.getElementById('adv_wall').value    = card.dataset.wall;
        document.getElementById('adv_floor').value   = card.dataset.floor;
        document.getElementById('adv_frame').value   = card.dataset.frame;
        document.getElementById('adv_lighting').value = card.dataset.lighting;
    }

    const slug = editSlugMap[card.dataset.venueId];
    document.getElementById('edit-venue-description').textContent = editVenueDescriptions[slug] || '';
}

// Venue card clicks
document.querySelectorAll('.edit-venue-card').forEach(card => {
    card.addEventListener('click', () => selectEditVenue(card));
});

// Advanced dropdowns override hidden inputs live
['adv_wall','adv_floor','adv_frame','adv_lighting'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('change', () => {
        const map = { adv_wall: 'edit_wall_texture', adv_floor: 'edit_floor_material', adv_frame: 'edit_frame_style', adv_lighting: 'edit_lighting_preset' };
        document.getElementById(map[id]).value = el.value;
    });
});

// Set description for whichever venue is already selected
const preselectedCard = document.querySelector('.edit-venue-card-inner.border-purple-500');
if (preselectedCard) {
    const card = preselectedCard.closest('.edit-venue-card');
    const slug = editSlugMap[card?.dataset?.venueId];
    if (slug) document.getElementById('edit-venue-description').textContent = editVenueDescriptions[slug] || '';
}
</script>
</x-app-layout>