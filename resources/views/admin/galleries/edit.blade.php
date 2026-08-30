<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'Edit Gallery: '.$gallery->title" :back="route('admin.galleries.index')" backLabel="All galleries"/>
    </x-slot>

    <!-- Dropzone CSS -->
    
    @vite(['resources/js/admin-vendor.js'])

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

        /* Venue card styles */
        .venue-card-inner {
            position: relative;
            border-radius: 0.5rem;
            overflow: hidden;
            transition: all 0.2s ease;
            cursor: pointer;
            border: 2px solid #374151;
            background: #1f2937;
        }
        .venue-card-inner.selected {
            border-color: #a855f7;
            background: rgba(168, 85, 247, 0.2);
            box-shadow: 0 0 0 2px rgba(168, 85, 247, 0.3);
        }
        .venue-check {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 20px;
            height: 20px;
            background: #a855f7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
            z-index: 5;
        }
        .venue-card-inner.selected .venue-check {
            opacity: 1;
        }
        .venue-lock-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }
        .venue-preview {
            position: relative;
            aspect-ratio: 1 / 1;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .venue-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .venue-preview-fallback {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .venue-meta {
            padding: 8px;
            text-align: center;
        }
        .venue-plan-badge {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 999px;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .venue-plan-badge-free { background: #374151; color: #9ca3af; }
        .venue-plan-badge-pro { background: #d97706; color: #fef3c7; }
        .venue-plan-badge-studio { background: #7c3aed; color: #e9d5ff; }
    /* ─── Reorder save bar (Round 4 polish) ─── */
    #reorder-save-bar {
        position: fixed;
        bottom: 1.5rem;
        left: 50%;
        transform: translateX(-50%);
        z-index: 1000;
        display: none; /* hidden by default; JS shows via display:flex */
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1.25rem;
        background: rgba(17, 24, 39, 0.95);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(139, 92, 246, 0.4);
        border-radius: 0.75rem;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(139, 92, 246, 0.1);
        font-size: 0.875rem;
        color: #e5e7eb;
        animation: reorder-bar-slide-up 0.25s ease-out;
    }
    @keyframes reorder-bar-slide-up {
        from { transform: translateX(-50%) translateY(20px); opacity: 0; }
        to   { transform: translateX(-50%) translateY(0);    opacity: 1; }
    }
    #reorder-save-bar .save-btn,
    #reorder-save-bar .discard-btn {
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        font-size: 0.8rem;
        transition: all 0.15s ease;
        cursor: pointer;
    }
    #reorder-save-bar .save-btn {
        background: linear-gradient(to right, #9333ea, #6366f1);
        color: white;
        border: none;
    }
    #reorder-save-bar .save-btn:hover:not(:disabled) {
        background: linear-gradient(to right, #7c3aed, #4f46e5);
        transform: translateY(-1px);
    }
    #reorder-save-bar .save-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    #reorder-save-bar .discard-btn {
        background: #374151;
        color: #d1d5db;
        border: 1px solid #4b5563;
    }
    #reorder-save-bar .discard-btn:hover {
        background: #4b5563;
        color: white;
    }

    /* Drag handle cursor on image cards */
    #gallery-grid .gallery-card {
        cursor: grab;
    }
    #gallery-grid .gallery-card:active {
        cursor: grabbing;
    }
    #gallery-grid .gallery-card.sortable-ghost {
        opacity: 0.3;
    }
    #gallery-grid .gallery-card.sortable-chosen {
        outline: 2px solid #9333ea;
        outline-offset: 2px;
    }

    </style>

    <!-- Toast container (rendered immediately) -->
    <div id="toast-container" class="toast-container"></div>

    <div class="page-shell space-y-6">

            {{-- Round 4: gallery sub-nav --}}
            <div class="flex flex-wrap gap-2 text-sm">
                <a href="{{ route('admin.galleries.events.index', $gallery) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-800 border border-gray-700 hover:border-purple-500 text-gray-300 hover:text-white transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Events
                    @if($gallery->scheduleEvents()->active()->upcoming()->count() > 0)
                        <span class="ml-1 px-1.5 py-0.5 rounded-full bg-purple-600 text-white text-xs font-bold">{{ $gallery->scheduleEvents()->active()->upcoming()->count() }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.galleries.analytics', $gallery) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-800 border border-gray-700 hover:border-purple-500 text-gray-300 hover:text-white transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    Analytics
                </a>
                @if($gallery->newsletterSignups()->exists())
                <a href="{{ route('admin.galleries.analytics', $gallery) }}#newsletter"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-800 border border-gray-700 hover:border-purple-500 text-gray-300 hover:text-white transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    {{ $gallery->newsletterSignups()->count() }} newsletter signups
                </a>
                @endif
            </div>

            {{-- ── ITERATION-2: Publish status bar (the publish moment) ────────
                 Draft → amber banner + Publish button (needs ≥1 artwork).
                 Live  → green banner + public link + Unpublish.
                 Placed ABOVE the settings form so publish state is the
                 first thing the curator sees, matching its product weight. --}}
            @php
                $imageCount  = $gallery->images()->count();
                $publicUrl   = $gallery->custom_domain
                    ? 'https://' . $gallery->custom_domain
                    : route('gallery.view', $gallery->slug);
                $canPublish  = $imageCount > 0;
            @endphp
            @if($gallery->is_active)
                <div class="bg-green-950/40 border border-green-700/40 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                    <span class="inline-flex items-center gap-2 flex-shrink-0">
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-60"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
                        </span>
                        <span class="text-green-300 font-semibold text-sm">Live</span>
                    </span>
                    <p class="flex-1 text-sm text-green-200/80">
                        This exhibition is public at
                        <a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="text-green-300 underline underline-offset-2 hover:text-green-200 break-all">{{ $publicUrl }}</a>
                    </p>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <button type="button" data-click="copyPublicLink" data-arg="{{ $publicUrl }}"
                                class="inline-flex items-center gap-1.5 bg-gray-700 hover:bg-gray-600 text-gray-100 text-sm font-medium px-3 py-2 rounded-lg transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            Copy link
                        </button>
                        <form action="{{ route('admin.galleries.unpublish', $gallery) }}" method="POST"
                              data-confirm="Unpublish this exhibition? The public link will stop working immediately.">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 bg-gray-700 hover:bg-gray-600 text-gray-300 text-sm font-medium px-3 py-2 rounded-lg transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                Unpublish
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <div class="bg-amber-950/40 border border-amber-700/40 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                    <span class="inline-flex items-center gap-2 flex-shrink-0">
                        <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span class="text-amber-300 font-semibold text-sm">Draft</span>
                    </span>
                    <p class="flex-1 text-sm text-amber-200/80">
                        {{ $canPublish
                            ? "Only you can see this exhibition. Publish when you're ready to go public."
                            : 'Only you can see this exhibition. Upload at least one artwork to enable publishing.' }}
                    </p>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <a href="{{ route('admin.galleries.preview', $gallery) }}" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 bg-gray-700 hover:bg-gray-600 text-gray-100 text-sm font-medium px-3 py-2 rounded-lg transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            Preview
                        </a>
                        <form action="{{ route('admin.galleries.publish', $gallery) }}" method="POST">
                            @csrf
                            <button type="submit" {{ $canPublish ? '' : 'disabled' }}
                                    title="{{ $canPublish ? 'Make this exhibition public' : 'Upload at least one artwork to publish' }}"
                                    class="inline-flex items-center gap-1.5 {{ $canPublish ? 'bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-500 hover:to-emerald-500 shadow-lg shadow-green-900/30' : 'bg-gray-700 cursor-not-allowed opacity-60' }} text-white text-sm font-semibold px-4 py-2 rounded-lg transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Publish
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            <!-- 1. Gallery Settings -->
            <div class="bg-gray-800 border border-gray-700 shadow-lg sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-100 mb-4">Gallery Settings</h3>

                <form action="{{ route('admin.galleries.update', $gallery) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Title -->
                        <div class="mb-4 md:col-span-2">
                            <label for="edit-title" class="block text-sm font-medium text-gray-400 mb-2">Title</label>
                            <input type="text" id="edit-title" name="title" value="{{ old('title', $gallery->title) }}" required
                                class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors">
                        </div>

                        <!-- Description -->
                        <div class="mb-4 md:col-span-2">
                            <label for="edit-description" class="block text-sm font-medium text-gray-400 mb-2">Description</label>
                            <textarea name="description" id="edit-description" rows="3" class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors">{{ old('description', $gallery->description) }}</textarea>
                        </div>

                        {{-- SEO OS (Iteration 6): curator-facing SEO overrides.
                             Leave blank to use the automatic titles/descriptions
                             generated from the gallery's real content. --}}
                        <div class="mb-4">
                            <label for="edit-seo-title" class="block text-sm font-medium text-gray-400 mb-2">
                                SEO title <span class="text-gray-600 font-normal">(optional — auto-generated when empty)</span>
                            </label>
                            <input type="text" id="edit-seo-title" name="seo_title" value="{{ old('seo_title', $gallery->seoProfile?->title_override) }}" maxlength="200"
                                   placeholder="{{ $gallery->title }} — 3D Virtual Exhibition"
                                   class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors">
                        </div>
                        <div class="mb-4">
                            <label for="edit-seo-description" class="block text-sm font-medium text-gray-400 mb-2">
                                SEO description <span class="text-gray-600 font-normal">(optional — max 300 chars)</span>
                            </label>
                            <textarea name="seo_description" id="edit-seo-description" rows="2" maxlength="300"
                                      placeholder="Shown in search results and social cards. Auto-generated from your description when empty."
                                      class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors">{{ old('seo_description', $gallery->seoProfile?->description_override) }}</textarea>
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
                        <h3 class="block text-sm font-medium text-gray-400 mb-3">Venue</h3>
                        @php
                        $venueAtmospheres = [
                            'white-cube'        => ['bg' => 'linear-gradient(135deg, #e8e8e8 0%, #c0c0c0 100%)',  'emoji' => 'WC', 'accent' => '#e0e0e0'],
                            'infinite-void'     => ['bg' => 'linear-gradient(135deg, #000000 0%, #0a0010 100%)',  'emoji' => 'IV', 'accent' => '#8b5cf6'],
                            'industrial-loft'   => ['bg' => 'linear-gradient(135deg, #2a2820 0%, #1a1610 100%)',  'emoji' => 'IL', 'accent' => '#8a7a50'],
                            'dark-museum'       => ['bg' => 'linear-gradient(135deg, #0a0a0a 0%, #1a0808 100%)',  'emoji' => 'DM', 'accent' => '#8b1a1a'],
                            'zen-gallery'       => ['bg' => 'linear-gradient(135deg, #2a2218 0%, #1a1810 100%)',  'emoji' => 'ZG', 'accent' => '#8b7355'],
                            'crystal-cathedral' => ['bg' => 'linear-gradient(135deg, #1a1a3a 0%, #0a0a2a 100%)',  'emoji' => 'CC', 'accent' => '#ddeeff'],
                            'nebula-drift'      => ['bg' => 'linear-gradient(135deg, #1a0530 0%, #050015 100%)',  'emoji' => 'ND', 'accent' => '#8844ff'],
                            'luxury-penthouse'  => ['bg' => 'linear-gradient(135deg, #0d0f18 0%, #060810 100%)',  'emoji' => 'LP', 'accent' => '#c9a84c'],
                            'cyber-gallery'     => ['bg' => 'linear-gradient(135deg, #020820 0%, #000412 100%)',  'emoji' => 'CG', 'accent' => '#00ffff'],
                            'sculpture-garden'  => ['bg' => 'linear-gradient(135deg, #87ceeb 0%, #4a8a3a 100%)',  'emoji' => 'SG', 'accent' => '#4ade80'],
                            'mirror-lake'       => ['bg' => 'linear-gradient(135deg, #0a0a18 0%, #202830 100%)',  'emoji' => 'ML', 'accent' => '#b0c8ff'],
                        ];
                        @endphp

                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3" id="edit-venue-cards">
                            @foreach($venueTemplates as $venue)
                                @php
                                    $accessible = $venue->isAccessibleBy(auth()->user());
                                    $isSelected = $gallery->venue_template_id == $venue->id;
                                    $atm = $venueAtmospheres[$venue->slug] ?? ['bg' => 'linear-gradient(135deg,#111,#222)', 'emoji' => '??', 'accent' => '#555'];
                                    $badgeClass = match($venue->plan_required) { 'pro' => 'venue-plan-badge-pro', 'studio' => 'venue-plan-badge-studio', default => 'venue-plan-badge-free' };
                                @endphp
                                <div class="edit-venue-card"
                                     data-venue-id="{{ $venue->id }}"
                                     data-wall="{{ $venue->default_settings['wall_texture'] }}"
                                     data-floor="{{ $venue->default_settings['floor_material'] }}"
                                     data-frame="{{ $venue->default_settings['frame_style'] }}"
                                     data-lighting="{{ $venue->default_settings['lighting_preset'] }}"
                                     data-layout="{{ $venue->default_settings['room_layout'] }}"
                                     data-accessible="{{ $accessible ? 'true' : 'false' }}"
                                     data-slug="{{ $venue->slug }}">

                                    <div class="venue-card-inner {{ $isSelected ? 'selected' : '' }}">

                                        <div class="venue-check">
                                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6l3 3 5-5" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </div>

                                        @if(!$accessible)
                                        <div class="venue-lock-overlay">
                                            <div style="text-align:center">
                                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 4px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                                                <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.8);letter-spacing:0.06em;text-transform:uppercase;">{{ ucfirst($venue->plan_required) }}</div>
                                            </div>
                                        </div>
                                        @endif

                                        <div class="venue-preview" style="background: {{ $atm['bg'] }};">
                                            <img src="/assets/thumbnails/{{ $venue->slug }}.jpg"
                                                 alt="{{ $venue->name }}"
                                                 class="venue-thumb-img"
                                                 loading="lazy">
                                            <div class="venue-preview-fallback" style="background: {{ $atm['bg'] }};">
                                                <span style="font-size:0.85rem;font-weight:700;letter-spacing:0.08em;color:rgba(255,255,255,0.7);">{{ $atm['emoji'] }}</span>
                                                <div style="position:absolute;bottom:8px;left:50%;transform:translateX(-50%);width:32px;height:3px;border-radius:2px;background:{{ $atm['accent'] }};opacity:0.6;box-shadow:0 0 8px {{ $atm['accent'] }};"></div>
                                            </div>
                                        </div>

                                        <div class="venue-meta">
                                            <div style="font-size:12px;font-weight:600;color:#e5e7eb;line-height:1.3;">{{ $venue->name }}</div>
                                            <div style="font-size:10px;color:#6b7280;margin-top:2px;">{{ $venue->capacityLabel() }}</div>
                                            <span class="venue-plan-badge {{ $badgeClass }}">{{ ucfirst($venue->plan_required) }}</span>
                                        </div>

                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Selected venue info bar --}}
                        <div id="edit-venue-info-bar" style="margin-top:12px;padding:10px 14px;border-radius:8px;background:rgba(139,92,246,0.08);border:1px solid rgba(139,92,246,0.2);display:none;align-items:center;gap:10px;">
                            <div id="edit-venue-info-accent" style="width:10px;height:10px;border-radius:50%;flex-shrink:0;"></div>
                            <div>
                                <div id="edit-venue-info-name" style="font-size:13px;font-weight:600;color:#e5e7eb;"></div>
                                <div id="edit-venue-info-desc" style="font-size:11px;color:#9ca3af;margin-top:1px;"></div>
                            </div>
                        </div>
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
                                <label for="input_wall_texture" class="block text-xs text-gray-500 mb-1">Wall</label>
                                <select id="adv_wall" class="block w-full rounded bg-gray-700 border-gray-600 text-gray-100 text-sm focus:border-purple-500">
                                    @foreach(['white'=>'White','concrete'=>'Concrete','brick'=>'Brick','wood'=>'Wood','plaster'=>'Plaster','marble'=>'Marble','velvet'=>'Velvet'] as $v=>$l)
                                        <option value="{{ $v }}" {{ $gallery->wall_texture == $v ? 'selected' : '' }}>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="input_floor_material" class="block text-xs text-gray-500 mb-1">Floor</label>
                                <select id="adv_floor" class="block w-full rounded bg-gray-700 border-gray-600 text-gray-100 text-sm focus:border-purple-500">
                                    @foreach(['wood'=>'Wood','marble'=>'Marble','concrete'=>'Concrete','terrazzo'=>'Terrazzo','grass'=>'Grass','sand'=>'Sand'] as $v=>$l)
                                        <option value="{{ $v }}" {{ $gallery->floor_material == $v ? 'selected' : '' }}>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="input_frame_style" class="block text-xs text-gray-500 mb-1">Frame</label>
                                <select id="adv_frame" class="block w-full rounded bg-gray-700 border-gray-600 text-gray-100 text-sm focus:border-purple-500">
                                    @foreach(['modern'=>'Modern (Black)','classic'=>'Classic (Gold)','minimal'=>'Minimal','gold'=>'Gold','silver'=>'Silver','bronze'=>'Bronze','black'=>'Black'] as $v=>$l)
                                        <option value="{{ $v }}" {{ $gallery->frame_style == $v ? 'selected' : '' }}>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="input_lighting_preset" class="block text-xs text-gray-500 mb-1">Lighting</label>
                                <select id="adv_lighting" class="block w-full rounded bg-gray-700 border-gray-600 text-gray-100 text-sm focus:border-purple-500">
                                    @foreach(['bright'=>'Bright','moody'=>'Moody','dramatic'=>'Dramatic'] as $v=>$l)
                                        <option value="{{ $v }}" {{ $gallery->lighting_preset == $v ? 'selected' : '' }}>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    {{-- ── Live Preview & Template Controls ──────────────────────
                        Drop-in partial that renders a side-by-side preview iframe
                        + slider sidebar with per-control hint cards. State is
                        persisted in #visual_overrides_json, which is parsed by
                        GalleryController::update() on form submit.
                    --}}
                    @include('admin.galleries.live-preview-panel', ['gallery' => $gallery])

                    <!-- Background Music (Pro Feature) -->
                    <div class="mb-6 mt-6 p-6 bg-gray-900/50 rounded-lg border border-gray-600">
                        <label class="block text-sm font-medium text-gray-300 mb-3">
                            Background Music
                            @if(!auth()->user()->isPro())
                                <span class="text-xs bg-purple-600 text-white px-2 py-0.5 rounded-full ml-2">Pro Only</span>
                            @endif
                        </label>

                        @if(auth()->user()->isPro())
                            <!-- AJAX Upload Container -->
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

                                <!-- Upload Input -->
                                <div class="relative">
                                    <input type="file" id="audio-upload-input" accept=".mp3,.wav,.m4a"
                                        data-change="uploadAudioFile"
                                        class="block w-full text-sm text-gray-300
                                            file:mr-4 file:py-2 file:px-4
                                            file:rounded-lg file:border-0
                                            file:text-sm file:font-semibold
                                            file:bg-purple-600 file:text-white
                                            hover:file:bg-purple-700
                                            cursor-pointer">

                                    <!-- Progress Bar (Hidden by default) -->
                                    <div id="audio-upload-progress" style="display:none;" class="mt-2">
                                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                                            <span id="audio-progress-text">Uploading...</span>
                                            <span id="audio-progress-percent">0%</span>
                                        </div>
                                        <div class="w-full h-2 bg-gray-700 rounded-full overflow-hidden">
                                            <div id="audio-progress-bar" class="h-full bg-gradient-to-r from-purple-500 to-indigo-500 transition-all duration-300" style="width: 0%"></div>
                                        </div>
                                    </div>

                                    <!-- Success Message (Hidden by default) -->
                                    <div id="audio-upload-success" style="display:none;" class="mt-2 p-2 bg-green-900/50 border border-green-700 rounded text-green-300 text-sm">
                                        <span id="audio-success-message">Audio uploaded successfully!</span>
                                    </div>

                                    <!-- Error Message (Hidden by default) -->
                                    <div id="audio-upload-error" style="display:none;" class="mt-2 p-3 bg-red-950/50 border border-red-700/60 rounded-lg flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2 text-red-300 text-sm">
                                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <span id="audio-error-message">Upload failed</span>
                                        </div>
                                        <button type="button" data-click="triggerFileInput" data-arg="audio-upload-input"
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

                    <!-- Custom Branding (Studio Feature) -->
                    <div class="mb-6 mt-6 p-6 bg-gray-900/50 rounded-lg border border-gray-600">
                        <label class="block text-sm font-medium text-gray-300 mb-3">
                            Custom Logo
                            @if(auth()->user()->plan !== 'studio')
                                <span class="text-xs bg-purple-600 text-white px-2 py-0.5 rounded-full ml-2">Studio Only</span>
                            @endif
                        </label>

                        @if(auth()->user()->plan === 'studio')
                            <!-- AJAX Upload Container -->
                            <div class="space-y-3">
                                <!-- Current Logo Preview -->
                                <div id="logo-preview-container" @if($gallery->custom_logo_path) @else style="display:none;" @endif class="bg-gray-700 rounded-lg p-3 mb-3 flex items-center justify-center">
                                    <img id="logo-preview-image"
                                         src="{{ $gallery->custom_logo_path ? asset('storage/' . $gallery->custom_logo_path) : '' }}"
                                         alt="Custom Logo"
                                         class="max-h-20 object-contain">
                                </div>

                                <!-- Upload Input -->
                                <div class="relative">
                                    <input type="file" id="logo-upload-input" accept=".png,.svg,.jpg,.jpeg"
                                        data-change="uploadLogoFile"
                                        class="block w-full text-sm text-gray-300
                                            file:mr-4 file:py-2 file:px-4
                                            file:rounded-lg file:border-0
                                            file:text-sm file:font-semibold
                                            file:bg-purple-600 file:text-white
                                            hover:file:bg-purple-700
                                            cursor-pointer">

                                    <!-- Progress Bar (Hidden by default) -->
                                    <div id="logo-upload-progress" style="display:none;" class="mt-2">
                                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                                            <span id="logo-progress-text">Uploading...</span>
                                            <span id="logo-progress-percent">0%</span>
                                        </div>
                                        <div class="w-full h-2 bg-gray-700 rounded-full overflow-hidden">
                                            <div id="logo-progress-bar" class="h-full bg-gradient-to-r from-purple-500 to-indigo-500 transition-all duration-300" style="width: 0%"></div>
                                        </div>
                                    </div>

                                    <!-- Success Message (Hidden by default) -->
                                    <div id="logo-upload-success" style="display:none;" class="mt-2 p-2 bg-green-900/50 border border-green-700 rounded text-green-300 text-sm">
                                        <span id="logo-success-message">Logo uploaded successfully!</span>
                                    </div>

                                    <!-- Error Message (Hidden by default) -->
                                    <div id="logo-upload-error" style="display:none;" class="mt-2 p-3 bg-red-950/50 border border-red-700/60 rounded-lg flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2 text-red-300 text-sm">
                                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <span id="logo-error-message">Upload failed</span>
                                        </div>
                                        <button type="button" data-click="triggerFileInput" data-arg="logo-upload-input"
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
                                <h4 class="text-orange-300 font-semibold text-sm mb-2">Studio Plan Benefits</h4>
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
                            <h3 class="block text-sm font-medium text-gray-300" id="schedule-label">Exhibition Schedule</h3>
                            <span class="text-xs bg-purple-900/50 text-purple-300 border border-purple-700/50 px-2 py-0.5 rounded-full">Pro</span>
                        </div>
                        <p class="text-xs text-gray-500 mb-4">Set an opening date and optional closing date. Visitors will see a countdown before opening and a "Closed" page after. Leave blank for always-open.</p>

                        @if(Auth::user()->isPro())
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="edit-opens-at" class="block text-xs font-medium text-gray-400 mb-1">Opens At</label>
                                    <input type="datetime-local" id="edit-opens-at" name="opens_at"
                                        value="{{ $gallery->opens_at ? $gallery->opens_at->format('Y-m-d\TH:i') : old('opens_at') }}"
                                        class="mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 shadow-sm transition-colors text-sm">
                                    <p class="text-xs text-gray-500 mt-1">Your local time. Leave blank to open immediately.</p>
                                </div>
                                <div>
                                    <label for="edit-closes-at" class="block text-xs font-medium text-gray-400 mb-1">Closes At</label>
                                    <input type="datetime-local" id="edit-closes-at" name="closes_at"
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
                                        <strong>Scheduled</strong> — Opens {{ $gallery->opens_at->diffForHumans() }}
                                        ({{ $gallery->opens_at->format('M j, Y \a\t g:i A') }})
                                    @elseif($gallery->hasClosed())
                                        <strong>Closed</strong> — Exhibition ended {{ $gallery->closes_at->diffForHumans() }}
                                    @else
                                        <strong>Open</strong> —
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

                    {{-- ============================================================
                         Round 4: Custom Domain + Branded Entrance Curtain
                         (Studio-plan only — non-Studio users see an upgrade CTA)

                         ONE @if($isStudio) block containing both features,
                         then ONE @else for the upgrade CTA, then ONE @endif.
                         ============================================================ --}}
                    @php
                        $planHolder = $gallery->team_id ? $gallery->team->owner : auth()->user();
                        $isStudio = $planHolder->plan === 'studio';
                    @endphp

                    @if($isStudio)
                        {{-- Custom Domain --}}
                        <div class="mt-6 pt-4 border-t border-gray-700">
                            <div class="flex items-center gap-2 mb-1">
                                <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                                <label for="edit-custom-domain" class="block text-sm font-medium text-gray-300">Custom Domain</label>
                                <span class="text-xs bg-amber-900/50 text-amber-300 border border-amber-700/50 px-2 py-0.5 rounded-full">Studio</span>
                            </div>
                            <p class="text-xs text-gray-500 mb-3">Point a CNAME at exospace.gallery and enter the domain here. Visitors will see this gallery at the root of your custom domain. DNS and SSL must be configured separately via Coolify.</p>
                            <div class="relative">
                                <span class="absolute left-3 top-2 text-gray-500 text-sm">https://</span>
                                <input type="text" id="edit-custom-domain" name="custom_domain"
                                    value="{{ old('custom_domain', $gallery->custom_domain) }}"
                                    placeholder="gallery.yourdomain.com"
                                    class="pl-16 mt-1 block w-full rounded-md bg-gray-700 border-gray-600 text-gray-100 focus:border-amber-500 focus:ring-2 focus:ring-amber-500/20 shadow-sm transition-colors text-sm font-mono"
                                    pattern="^([a-z0-9-]+\.)+[a-z]{2,}$">
                            </div>
                            @error('custom_domain')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
                            @if($gallery->custom_domain)
                            <p class="text-xs text-green-400 mt-2">Active — visitors at <a href="https://{{ $gallery->custom_domain }}" target="_blank" class="underline">{{ $gallery->custom_domain }}</a> see this gallery.</p>
                            @endif

                            {{-- (Task H63) — DNS verification UI. Shows the TXT
                                 record the user must add + a "Verify domain" button.
                                 Wired to the galleries.verify-domain route from
                                 Iteration 02. --}}
                            @include('admin.galleries._custom-domain-verification')
                        </div>

                        {{-- Branded Entrance Curtain --}}
                        <div class="mt-6 pt-4 border-t border-gray-700">
                            <div class="flex items-center gap-2 mb-1">
                                <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <h3 class="block text-sm font-medium text-gray-300">Branded Entrance Curtain</h3>
                                <span class="text-xs bg-amber-900/50 text-amber-300 border border-amber-700/50 px-2 py-0.5 rounded-full">Studio</span>
                            </div>
                            <p class="text-xs text-gray-500 mb-3">Replace the default "EXOSPACE" entrance curtain with your own logo and background color. White-label your exhibition entrance.</p>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-400 mb-1">Custom curtain logo</label>
                                    <input type="file" name="curtain_logo" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                                           class="w-full text-sm text-gray-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-gray-700 file:text-gray-200 hover:file:bg-gray-600">
                                    <p class="text-xs text-gray-500 mt-1">PNG / JPG / SVG / WEBP, max 2 MB. Recommended: wide aspect, transparent background.</p>
                                    @if($gallery->curtain_logo_path)
                                        <div class="mt-2 flex items-center gap-3">
                                            <img src="{{ asset('storage/' . $gallery->curtain_logo_path) }}" alt="Curtain logo" class="h-10 max-w-[160px] object-contain bg-gray-900 rounded border border-gray-700 px-2">
                                            <label class="text-xs text-gray-400 flex items-center gap-1">
                                                <input type="checkbox" name="clear_curtain_logo" value="1" class="rounded bg-gray-700 border-gray-600 text-purple-600">
                                                Remove
                                            </label>
                                        </div>
                                    @endif
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-400 mb-1">Custom background color</label>
                                    <div class="flex items-center gap-2">
                                        <input type="color" name="curtain_bg_color"
                                               value="{{ old('curtain_bg_color', $gallery->curtain_bg_color ?? '#0a0a14') }}"
                                               class="w-12 h-10 rounded border border-gray-600 bg-gray-700 cursor-pointer">
                                        <input type="text" name="curtain_bg_color_text"
                                               value="{{ old('curtain_bg_color', $gallery->curtain_bg_color) }}"
                                               placeholder="#0a0a14"
                                               class="flex-1 rounded-md bg-gray-700 border-gray-600 text-gray-100 px-3 py-2 text-sm font-mono"
                                               data-input="syncCurtainColorPreview">
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">Override the default dark gradient with a solid color.</p>
                                    <label class="text-xs text-gray-400 flex items-center gap-1 mt-2">
                                        <input type="checkbox" name="clear_curtain_bg" value="1" class="rounded bg-gray-700 border-gray-600 text-purple-600">
                                        Reset to default gradient
                                    </label>
                                </div>
                            </div>
                        </div>
                    @else
                        {{-- Non-Studio: upgrade CTA --}}
                        <div class="mt-6 pt-4 border-t border-gray-700">
                            <div class="flex items-center justify-between gap-4 bg-gray-800/60 border border-dashed border-gray-600 rounded-lg px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                                    <p class="text-sm text-gray-400">Custom domain + branded entrance curtain are Studio-plan features.</p>
                                </div>
                                <a href="/pricing" class="flex-shrink-0 text-xs font-semibold text-amber-400 hover:text-amber-300 border border-amber-600/40 hover:border-amber-500 px-3 py-1.5 rounded-lg transition whitespace-nowrap">
                                    Studio — $99
                                </a>
                            </div>
                        </div>
                    @endif

                    <div class="flex justify-end items-center gap-3 mt-6 pt-4 border-t border-gray-700">
                        <!-- Inline save feedback — shown right next to the button -->
                        <div id="save-feedback" class="hidden items-center gap-2 text-sm font-medium">
                            <svg id="save-feedback-icon-ok" class="hidden w-4 h-4 text-green-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            <svg id="save-feedback-icon-err" class="hidden w-4 h-4 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                            <span id="save-feedback-text" class="text-green-400"></span>
                        </div>
                        <a href="{{ route('admin.galleries.index') }}" class="bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white px-4 py-2 rounded-lg transition-colors">
                            Cancel
                        </a>
                        <button type="submit" id="update-settings-btn"
                                class="btn btn-primary disabled:opacity-60">
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
                    // ITERATION-2 (plan-copy alignment): quota display is
                    // PLAN-HOLDER based (team galleries bill against the
                    // team owner — previously showed the acting editor's
                    // limits) and reflects the real semantics: max_images
                    // is a TOTAL across all the holder's galleries, not
                    // a per-gallery cap.
                    $planHolder = $gallery->team_id ? $gallery->team->owner : Auth::user();
                    $imgCount   = $gallery->images()->count();
                    $imgUsed    = $planHolder->currentImageCount();
                    $imgMax     = $planHolder->max_images;
                    $imgPct     = $imgMax > 0 ? min(($imgUsed / $imgMax) * 100, 100) : 0;
                    $imgNear    = $imgPct >= 80;
                    $imgFull    = $imgUsed >= $imgMax;
                @endphp

                @if($imgNear || $imgFull)
                @php $imgFullClass = $imgFull ? 'bg-red-950/40 border-red-700/50' : 'bg-amber-950/40 border-amber-600/50'; @endphp
<div class="mb-4 flex items-center gap-3 {{ $imgFullClass }} border rounded-xl px-4 py-3">
                    <svg class="w-4 h-4 {{ $imgFull ? 'text-red-400' : 'text-amber-400' }} flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <p class="flex-1 text-sm {{ $imgFull ? 'text-red-200' : 'text-amber-200' }}">
                        @if($imgFull)
                            You've reached your plan's {{ $imgMax }}-image total (across all galleries).
                            @if(!$planHolder->isPro()) Upgrade to Pro for 100 images total. @endif
                        @else
                            @php $slotsLeft = $imgMax - $imgUsed; @endphp
                            {{ $imgUsed }} of {{ $imgMax }} images used across your galleries — {{ $slotsLeft }} slot{{ $slotsLeft === 1 ? '' : 's' }} remaining.
                        @endif
                    </p>
                    @if(!$planHolder->isPro())
                    <a href="/pricing" class="flex-shrink-0 text-xs font-semibold {{ $imgFull ? 'bg-red-600 hover:bg-red-500' : 'bg-amber-600 hover:bg-amber-500' }} text-white px-3 py-1.5 rounded-lg transition whitespace-nowrap">
                        Upgrade
                    </a>
                    @endif
                </div>
                @endif

                @if($imgFull)
                <div class="border-2 border-dashed border-gray-700 rounded-lg bg-gray-900/30 px-6 py-10 text-center">
                    <svg class="w-10 h-10 text-gray-600 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    <p class="text-gray-500 text-sm">Upload limit reached — {{ $imgUsed }} of {{ $imgMax }} images used on your plan.</p>
                    @if(!$planHolder->isPro())
                        <a href="/pricing" class="text-xs text-purple-400 hover:text-purple-300 mt-2 inline-block underline underline-offset-2 transition">Upgrade for more image slots →</a>
                    @endif
                </div>
                @else
                <form action="{{ route('admin.images.store', $gallery) }}"
                      class="dropzone border-dashed border-2 border-gray-600 rounded-lg bg-gray-900/40 hover:bg-gray-900/70 transition-all duration-300 cursor-pointer"
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
                                <input type="checkbox" id="select-all-checkbox" data-change="toggleSelectAll"
                                       class="w-4 h-4 rounded border-gray-600 bg-gray-700 text-purple-600 focus:ring-2 focus:ring-purple-500 focus:ring-offset-0 focus:ring-offset-gray-800">
                                <span>Select All</span>
                            </label>
                        @endif
                    </div>
                    <button id="bulk-delete-btn" data-click="bulkDelete" style="display: none;"
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
                            <div class="gallery-card relative group bg-gray-900 border border-gray-700 rounded-lg overflow-hidden hover:border-purple-500 hover:-translate-y-1 hover:shadow-xl hover:shadow-purple-900/20" id="image-{{ $image->id }}" data-id="{{ $image->id }}"
                                 data-metadata='{{ json_encode([
                                     'id'             => $image->id,
                                     'title'          => $image->title,
                                     'description'    => $image->description,
                                     'artist_id'      => $image->artist_id,
                                     'price'          => $image->price,
                                     'currency'       => $image->currency,
                                     'for_sale'       => (bool) $image->for_sale,
                                     'medium'         => $image->medium,
                                     'year'           => $image->year,
                                     'dimensions'     => $image->dimensions,
                                     'edition_size'   => $image->edition_size,
                                     'edition_number' => $image->edition_number,
                                     'external_url'   => $image->external_url,
                                 ]) }}'>

                                <!-- 3B: Selection Checkbox -->
                                <div class="absolute top-3 left-3 z-20 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                                    <input type="checkbox" value="{{ $image->id }}"
                                           data-change="updateSelection"
                                           class="image-checkbox w-5 h-5 rounded border-gray-600 bg-gray-700 text-purple-600 shadow-lg focus:ring-2 focus:ring-purple-500 cursor-pointer transition-all">
                                </div>

                                <!-- Image: Enforced Aspect Ratio (Square) -->
                                <div class="aspect-square w-full bg-gray-950 overflow-hidden">
                                    <img src="{{ $image->public_url }}"
                                         srcset="{{ $image->srcset }}"
                                         sizes="150px"
                                         alt="{{ $image->original_name }}"
                                         loading="lazy" decoding="async"
                                         class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                                </div>

                                <!-- Delete Button: Pro Style -->
                                <button data-click="deleteImage" data-arg="{{ $image->id }}"
                                        type="button"
                                        class="absolute top-3 right-3 bg-red-600/80 hover:bg-red-600 text-white w-8 h-8 flex items-center justify-center rounded-full shadow-lg transition-all duration-200 z-10 opacity-0 group-hover:opacity-100 transform scale-90 group-hover:scale-100"
                                        title="Delete Image">
                                    <span class="text-lg font-bold leading-none">&times;</span>
                                </button>

                                <!-- Edit Details Button (ITERATION-2: artwork metadata editor) -->
                                <button data-click="editMetadata" data-arg="{{ $image->id }}"
                                        type="button"
                                        class="absolute top-3 left-14 bg-gray-800/80 hover:bg-purple-600 text-gray-200 hover:text-white w-8 h-8 flex items-center justify-center rounded-full shadow-lg transition-all duration-200 z-10 opacity-0 group-hover:opacity-100 transform scale-90 group-hover:scale-100"
                                        title="Edit artwork details (title, price, artist…)">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </button>

                                <!-- Caption (ITERATION-2: shows curated title + price, not the filename) -->
                                <div class="p-3 bg-gray-900 border-t border-gray-800">
                                    <p class="text-xs {{ $image->title ? 'text-gray-300' : 'text-gray-500' }} truncate text-center font-medium" data-role="caption-title">
                                        {{ $image->title ?: $image->original_name }}
                                    </p>
                                    @if($image->for_sale && $image->price)
                                        <p class="text-xs text-green-400 text-center mt-0.5" data-role="caption-price">{{ $image->formattedPrice() }}</p>
                                    @endif
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

            {{-- ── ITERATION-2: Artwork metadata editor modal ───────────────────
                 Wires the previously ORPHANED ImageMetadataController endpoint
                 (PUT /admin/galleries/{gallery}/images/{image}/metadata —
                 routed since Round 4 but called by nothing) to a per-artwork
                 editor. Populated from the card's data-metadata blob; saved
                 via fetch, card caption updates in place. --}}
            <div id="metadata-modal" role="dialog" aria-modal="true" aria-labelledby="metadata-modal-title"
                 style="display:none; position:fixed; inset:0; z-index:1100; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px);"
                 class="items-center justify-center p-4 overflow-y-auto"
                 data-click="closeMetadataModalIfBackdrop">
                <div class="bg-gray-800 border border-gray-700 rounded-2xl shadow-2xl w-full max-w-2xl mx-auto my-8" role="document">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-700">
                        <h3 id="metadata-modal-title" class="text-lg font-semibold text-gray-100">Artwork details</h3>
                        <button type="button" data-click="closeMetadataModal" aria-label="Close"
                                class="text-gray-500 hover:text-gray-300 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <form id="metadata-form" data-submit="saveMetadata" class="px-6 py-5 space-y-4 max-h-[70vh] overflow-y-auto">
                        <input type="hidden" id="metadata-image-id" name="image_id">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2">
                                <label for="metadata-title" class="block text-sm font-medium text-gray-300 mb-1">Title</label>
                                <input type="text" id="metadata-title" name="title" maxlength="255" placeholder="e.g. Untitled (Blue Room)"
                                       class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>

                            <div class="sm:col-span-2">
                                <label for="metadata-description" class="block text-sm font-medium text-gray-300 mb-1">Description</label>
                                <textarea id="metadata-description" name="description" rows="3" maxlength="1000" placeholder="Shown next to the artwork in the 3D viewer"
                                          class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-purple-500"></textarea>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="metadata-artist" class="block text-sm font-medium text-gray-300 mb-1">Artist</label>
                                <select id="metadata-artist" name="artist_id"
                                        class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                    <option value="">— No artist attribution —</option>
                                    @foreach($artistOptions ?? [] as $artistId => $artistName)
                                        <option value="{{ $artistId }}">{{ $artistName }}</option>
                                    @endforeach
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Manage artists in <a href="{{ route('admin.artists.index') }}" class="text-purple-400 hover:text-purple-300 underline underline-offset-2">Artist profiles</a>.</p>
                            </div>

                            <div>
                                <label for="metadata-medium" class="block text-sm font-medium text-gray-300 mb-1">Medium</label>
                                <input type="text" id="metadata-medium" name="medium" maxlength="255" placeholder="e.g. Oil on canvas"
                                       class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>

                            <div>
                                <label for="metadata-year" class="block text-sm font-medium text-gray-300 mb-1">Year</label>
                                <input type="number" id="metadata-year" name="year" min="1000" max="{{ date('Y') + 1 }}" placeholder="{{ date('Y') }}"
                                       class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>

                            <div>
                                <label for="metadata-dimensions" class="block text-sm font-medium text-gray-300 mb-1">Dimensions</label>
                                <input type="text" id="metadata-dimensions" name="dimensions" maxlength="100" placeholder="e.g. 120 × 90 cm"
                                       class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>

                            <div>
                                <label for="metadata-external-url" class="block text-sm font-medium text-gray-300 mb-1">External link</label>
                                <input type="url" id="metadata-external-url" name="external_url" maxlength="500" placeholder="https://…"
                                       class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>

                            <div class="sm:col-span-2 border-t border-gray-700 pt-4">
                                <label class="flex items-center gap-2.5 text-sm text-gray-200 cursor-pointer select-none">
                                    <input type="checkbox" id="metadata-for-sale" name="for_sale" value="1"
                                           class="w-4 h-4 rounded border-gray-600 bg-gray-700 text-purple-600 focus:ring-2 focus:ring-purple-500">
                                    For sale — show price in the viewer
                                </label>
                            </div>

                            <div>
                                <label for="metadata-price" class="block text-sm font-medium text-gray-300 mb-1">Price</label>
                                <input type="number" id="metadata-price" name="price" min="0" step="0.01" max="99999999.99" placeholder="0.00"
                                       class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>

                            <div>
                                <label for="metadata-currency" class="block text-sm font-medium text-gray-300 mb-1">Currency</label>
                                <select id="metadata-currency" name="currency"
                                        class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                    <option value="USD">USD — $</option>
                                    <option value="EUR">EUR — €</option>
                                    <option value="GBP">GBP — £</option>
                                    <option value="PKR">PKR — Rs</option>
                                </select>
                            </div>

                            <div class="sm:col-span-2 border-t border-gray-700 pt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label for="metadata-edition-size" class="block text-sm font-medium text-gray-300 mb-1">Edition size</label>
                                    <input type="number" id="metadata-edition-size" name="edition_size" min="1" placeholder="e.g. 12 for a limited edition"
                                           class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                </div>
                                <div>
                                    <label for="metadata-edition-number" class="block text-sm font-medium text-gray-300 mb-1">Edition number</label>
                                    <input type="text" id="metadata-edition-number" name="edition_number" maxlength="50" placeholder="e.g. 3/12"
                                           class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-700">
                            <button type="button" data-click="closeMetadataModal"
                                    class="bg-gray-700 hover:bg-gray-600 text-gray-200 font-medium px-4 py-2 rounded-lg transition text-sm">Cancel</button>
                            <button type="submit" id="metadata-save-btn"
                                    class="btn btn-sm btn-primary">
                                Save details
                            </button>
                        </div>
                    </form>
                </div>
            </div>
    </div>

    <!-- Dropzone & Scripts -->
    
    <script nonce="@nonce">
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
        // FIX (Iter-002): this used to be assigned directly to
        // `Dropzone.options.imageUploadDropzone`, which threw
        // "Dropzone is not defined" whenever this script ran before the
        // async @@vite('admin-vendor.js') module (which sets window.Dropzone)
        // had finished loading — a plain classic <script> doesn't wait for a
        // type="module" script to finish. Made this a plain object (no
        // reference to the Dropzone global) and moved the actual
        // Dropzone.autoDiscover / instantiation into a ready-check bootstrap
        // below, which also re-attaches correctly after Turbo navigations
        // instead of relying on Dropzone's own one-shot DOMContentLoaded
        // auto-discovery.
        const exospaceDropzoneOptions = {
            paramName: "file",
            maxFilesize: 10,
            maxFiles: 100,
            parallelUploads: 2,
            timeout: 180000,
            acceptedFiles: ".jpeg,.jpg,.png,.webp",
            dictDefaultMessage: "<span class='text-purple-400 font-bold text-lg'>Drag your artwork here</span> or <span class='underline cursor-pointer'>browse</span><br><span class='text-xs text-gray-500 mt-2 block'>Supports JPG, PNG, WEBP (Max 10MB)</span>",
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
                        console.log(`Uploaded ${uploadedCount}/${totalFiles}: ${file.name}`);
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

                    console.error(`Upload failed for ${file.name}:`, cleanError);
                });

                this.on("queuecomplete", function() {
                    console.log(`Queue complete! Uploaded: ${uploadedCount}/${totalFiles}`);

                    if (failedFiles.length > 0) {
                        const errHtml = failedFiles.map(f => `<li class="text-red-300 text-xs">• <strong>${f.name}</strong>: ${f.error}</li>`).join('');
                        const banner = document.createElement('div');
                        banner.className = 'mt-3 p-3 bg-red-950/60 border border-red-700/60 rounded-lg text-sm';
                        banner.innerHTML = `<p class="text-red-300 font-semibold mb-1.5">[!] ${failedFiles.length} file${failedFiles.length > 1 ? 's' : ''} failed to upload:</p><ul class="space-y-0.5">${errHtml}</ul><p class="text-red-400/70 text-xs mt-2">Common fixes: reduce file size below 10MB, use JPG/PNG/WEBP format.</p>`;
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

        // ─── Dropzone bootstrap ───────────────────────────────
        // Waits for admin-vendor.js to finish loading (window.Dropzone),
        // then manually attaches to #image-upload-dropzone. Runs on initial
        // load and on every turbo:load so it also works after navigating to
        // this page via Turbo (Dropzone's built-in autoDiscover only ever
        // runs once, on the page's first DOMContentLoaded).
        (function initGalleryDropzone(attemptsLeft) {
            if (typeof Dropzone === 'undefined') {
                if (attemptsLeft === undefined) attemptsLeft = 30;
                if (attemptsLeft <= 0) {
                    console.error('Dropzone.js failed to load — image upload widget not initialized.');
                    return;
                }
                setTimeout(() => initGalleryDropzone(attemptsLeft - 1), 100);
                return;
            }
            Dropzone.autoDiscover = false;
            const el = document.getElementById('image-upload-dropzone');
            if (el && !el.dropzone) {
                new Dropzone(el, exospaceDropzoneOptions);
            }
        })();
        document.addEventListener('turbo:load', () => {
            const el = document.getElementById('image-upload-dropzone');
            if (el && !el.dropzone && typeof Dropzone !== 'undefined') {
                new Dropzone(el, exospaceDropzoneOptions);
            }
        });

        // ── CSP-safe helper functions for data-click / data-input attributes ──
        // These replace inline onclick="..." / oninput="..." handlers that CSP blocks.
        // The delegated listener in layouts/app.blade.php dispatches to these.
        // ITERATION-2 (publish moment): copy the public gallery URL from the
        // Live status bar. Resolved by the CSP-safe data-click delegator.
        window.copyPublicLink = function(url) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function() {
                    toast('Gallery link copied!', 'success');
                });
            } else {
                // Legacy fallback for non-secure contexts (plain HTTP dev).
                const input = document.createElement('input');
                input.value = url;
                document.body.appendChild(input);
                input.select();
                try { document.execCommand('copy'); toast('Gallery link copied!', 'success'); }
                catch (err) { toast('Could not copy — please copy from the address bar', 'error'); }
                input.remove();
            }
        };

        window.triggerFileInput = function(inputId) {
            const el = document.getElementById(inputId);
            if (el) el.click();
        };
        window.syncCurtainColorPreview = function(input) {
            // Sync the text color input to the hidden color input (and vice-versa)
            const colorInput = document.querySelector('input[name="curtain_bg_color"]');
            if (colorInput && input.value) colorInput.value = input.value;
        };
        // ─── ITERATION-2: Artwork metadata editor (orphaned endpoint wired) ──
        // PUT /admin/galleries/{gallery}/images/{image}/metadata — the
        // endpoint existed since Round 4 but nothing called it. These
        // handlers are resolved by the CSP-safe data-click/data-submit
        // delegators in layouts/app.blade.php.
        const METADATA_FIELDS = ['title', 'description', 'artist_id', 'price', 'currency',
                                 'medium', 'year', 'dimensions', 'edition_size', 'edition_number', 'external_url'];
        const metadataModal = () => document.getElementById('metadata-modal');

        window.editMetadata = function(id) {
            const card = document.getElementById(`image-${id}`);
            if (!card || !metadataModal()) return;

            let meta = {};
            try { meta = JSON.parse(card.dataset.metadata || '{}'); } catch (e) { /* fall through with empty */ }
            document.getElementById('metadata-image-id').value = id;

            for (const field of METADATA_FIELDS) {
                const input = document.getElementById(`metadata-${field.replace(/_/g, '-')}`);
                if (!input) continue;
                if (input.type === 'checkbox') {
                    input.checked = !!meta.for_sale;
                } else {
                    input.value = meta[field] ?? (field === 'currency' ? 'USD' : '');
                }
            }

            metadataModal().style.display = 'flex';
        };

        window.closeMetadataModal = function() {
            if (metadataModal()) metadataModal().style.display = 'none';
        };

        window.closeMetadataModalIfBackdrop = function(el, e) {
            // Only close if the click landed directly on the backdrop.
            if (e.target === el) el.style.display = 'none';
        };

        // Escape closes the metadata modal (registered once per page load).
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') window.closeMetadataModal();
        });

        window.saveMetadata = function(form, e) {
            e.preventDefault();
            const id = document.getElementById('metadata-image-id').value;
            const card = document.getElementById(`image-${id}`);
            if (!id || !card) return;

            const saveBtn = document.getElementById('metadata-save-btn');
            const payload = {};
            for (const field of METADATA_FIELDS) {
                const input = document.getElementById(`metadata-${field.replace(/_/g, '-')}`);
                if (!input) continue;
                if (input.type === 'checkbox') {
                    payload.for_sale = input.checked;
                } else {
                    // Cleared fields send explicit nulls so the backend
                    // overwrites the old value — omitting the key would
                    // silently keep it (partial-update semantics).
                    payload[field] = input.value === '' ? null : input.value;
                }
            }
            // Currency always submits so the select's default doesn't stick
            // when the curator clears the price.
            payload.currency = document.getElementById('metadata-currency').value;

            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving…';

            fetch(`/admin/galleries/{{ $gallery->id }}/images/${id}/metadata`, {
                method: 'PUT',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (ok && data.success) {
                    // Reflect saved values on the card (title, price, metadata blob).
                    const img = data.image || {};
                    const captionTitle = card.querySelector('[data-role="caption-title"]');
                    if (captionTitle) {
                        captionTitle.textContent = img.title || card.querySelector('img')?.alt || '';
                        captionTitle.classList.toggle('text-gray-300', !!img.title);
                        captionTitle.classList.toggle('text-gray-500', !img.title);
                    }
                    let priceEl = card.querySelector('[data-role="caption-price"]');
                    if (data.formatted_price) {
                        if (!priceEl) {
                            priceEl = document.createElement('p');
                            priceEl.className = 'text-xs text-green-400 text-center mt-0.5';
                            priceEl.setAttribute('data-role', 'caption-price');
                            captionTitle?.after(priceEl);
                        }
                        priceEl.textContent = data.formatted_price;
                    } else if (priceEl) {
                        priceEl.remove();
                    }
                    card.dataset.metadata = JSON.stringify({
                        id: Number(id),
                        title: img.title ?? null,
                        description: img.description ?? null,
                        artist_id: img.artist_id ?? null,
                        price: img.price ?? null,
                        currency: img.currency ?? null,
                        for_sale: !!img.for_sale,
                        medium: img.medium ?? null,
                        year: img.year ?? null,
                        dimensions: img.dimensions ?? null,
                        edition_size: img.edition_size ?? null,
                        edition_number: img.edition_number ?? null,
                        external_url: img.external_url ?? null,
                    });
                    window.closeMetadataModal();
                    toast(data.message || 'Artwork details saved.', 'success');
                } else {
                    const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
                    toast(first || data.message || 'Could not save — check the fields and try again.', 'error');
                }
            })
            .catch(() => toast('Network error — please try again.', 'error'))
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save details';
            });
        };

        window.closestOverlayRemove = function(el) {
            // Used by Cancel buttons inside dynamically-inserted confirm overlays
            const overlay = el.closest('.absolute');
            if (overlay) overlay.remove();
        };
        window.removeBulkConfirmBar = function() {
            const bar = document.getElementById('bulk-confirm-bar');
            if (bar) bar.remove();
        };
        window.closeUnsavedChangesModal = function() {
            const m = document.getElementById('unsaved-changes-modal');
            if (m) m.style.display = 'none';
        };
        window.closeUnsavedChangesModalIfBackdrop = function(el, e) {
            // Only close if the click landed directly on the backdrop (not modal content)
            if (e.target === el) el.style.display = 'none';
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
                    <button data-click="confirmDeleteImage" data-arg="${id}" class="bg-red-600 hover:bg-red-500 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">Delete</button>
                    <button data-click="closestOverlayRemove" class="bg-gray-700 hover:bg-gray-600 text-gray-300 text-xs font-medium px-3 py-1.5 rounded-lg transition">Cancel</button>
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
                <button data-click="executeBulkDelete" data-arg="${ids.join(',')}" class="flex-shrink-0 bg-red-600 hover:bg-red-500 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">Confirm Delete</button>
                <button data-click="removeBulkConfirmBar" class="flex-shrink-0 text-xs text-gray-400 hover:text-gray-200 px-2 py-1.5 transition">Cancel</button>`;
            document.getElementById('bulk-delete-btn').after(bar);
        }

        function executeBulkDelete(ids) {
            // Accept either an array (legacy) or a comma-separated string (CSP-safe data-arg)
            if (typeof ids === 'string') {
                ids = ids.split(',').map(s => parseInt(s.trim(), 10)).filter(n => !isNaN(n));
            }
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
        // ─── AJAX form submit — no page navigation so no "leave site" dialog ───
        document.querySelector('form[action*="galleries"]').addEventListener('submit', function(e) {
            e.preventDefault();
            const form = this;
            const btn = document.getElementById('update-settings-btn');
            document.getElementById('update-spinner').classList.remove('hidden');
            document.getElementById('update-label').textContent = 'Saving…';
            btn.disabled = true;

            // Sync advanced dropdown overrides into hidden fields before serialising
            ['adv_wall','adv_floor','adv_frame','adv_lighting'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                const map = { adv_wall: 'edit_wall_texture', adv_floor: 'edit_floor_material', adv_frame: 'edit_frame_style', adv_lighting: 'edit_lighting_preset' };
                document.getElementById(map[id]).value = el.value;
            });

            // Add _method=PUT for Laravel
            const fd = new FormData(form);
            fd.set('_method', 'PUT');

            fetch(form.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: fd
            })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                document.getElementById('update-spinner').classList.add('hidden');
                document.getElementById('update-label').textContent = 'Update Settings';
                btn.disabled = false;
                if (ok) {
                    dirty = false;
                    showSaveFeedback('Settings updated', true);
                } else {
                    const errors = data.errors ? Object.values(data.errors).flat().join(' ') : (data.message || 'Could not save — please try again');
                    showSaveFeedback(errors, false);
                }
            })
            .catch(() => {
                document.getElementById('update-spinner').classList.add('hidden');
                document.getElementById('update-label').textContent = 'Update Settings';
                btn.disabled = false;
                showSaveFeedback('Network error — please try again', false);
            });
        });

        // Unsaved changes guard — uses custom in-page modal instead of native browser dialog
        var dirty = false;
        var _pauseDirty = false; // paused while venue card selection syncs dropdowns
        (function() {
            let ready = false;
            const form = document.querySelector('form[action*="galleries"]');
            if (!form) return;
            setTimeout(function() {
                ready = true;
                form.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(el => {
                    el.addEventListener('change', () => { if (ready && !_pauseDirty) dirty = true; });
                    el.addEventListener('input',  () => { if (ready && !_pauseDirty) dirty = true; });
                });
            }, 800);
            // Intercept all nav links so we can show custom modal instead of browser dialog
            document.querySelectorAll('a[href]').forEach(link => {
                link.addEventListener('click', function(e) {
                    if (!dirty) return;
                    const href = this.href;
                    if (!href || href.startsWith('#') || href.startsWith('javascript')) return;
                    e.preventDefault();
                    showUnsavedModal(href);
                });
            });
        })();

        function showUnsavedModal(destination) {
            const modal = document.getElementById('unsaved-changes-modal');
            document.getElementById('unsaved-leave-btn').onclick = () => {
                dirty = false;
                window.location.href = destination;
            };
            modal.style.display = 'flex';
        }
    </script>

    <!-- Reorder save bar -->
    <div id="reorder-save-bar">
        <svg class="w-4 h-4 text-purple-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
        <span>Order changed</span>
        <button class="save-btn" data-click="saveOrder">Save Order</button>
        <button class="discard-btn" data-click="discardOrder">Discard</button>
    </div>

    <script nonce="@nonce">
        // Warn on navigate if order changed but not saved — use custom modal, not native dialog
        window._reorderHandler = e => {
            const reorderBar = document.getElementById('reorder-save-bar');
            if (reorderBar &&
                getComputedStyle(reorderBar).display !== 'none' &&
                reorderBar.style.display !== 'none') {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        // Intercept nav links to show custom modal when reorder bar is visible
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a[href]');
            if (!link) return;
            const reorderBar = document.getElementById('reorder-save-bar');
            const reorderVisible = reorderBar &&
                getComputedStyle(reorderBar).display !== 'none' &&
                reorderBar.style.display !== 'none';
            if (!reorderVisible) return;
            const href = link.href;
            if (!href || href.startsWith('#') || href.startsWith('javascript')) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            showUnsavedModal(href);
        }, true);

        // ─── BUGFIX: SortableJS init + saveOrder/discardOrder (Round 4) ────
        // FIX (Iter-002): Sortable is loaded async via @@vite('admin-vendor.js').
        // The old code did a single check and permanently gave up ("Reorder
        // init skipped") if that module hadn't finished loading yet — which
        // also meant window.saveOrder/discardOrder never got defined for the
        // rest of the page's life. Retry for a few seconds instead of
        // bailing on the first attempt.
        (function initReorder(attemptsLeft) {
            const grid = document.getElementById('gallery-grid');
            if (!grid || typeof Sortable === 'undefined') {
                if (attemptsLeft === undefined) attemptsLeft = 30;
                if (attemptsLeft <= 0) {
                    console.warn('Reorder init skipped: grid or Sortable not available');
                    return;
                }
                setTimeout(() => initReorder(attemptsLeft - 1), 100);
                return;
            }

            let originalOrder = [];
            let sortableInstance = null;

            function snapshotOrder() {
                originalOrder = Array.from(grid.children).map(el => el.cloneNode(true));
            }

            function showBar() {
                const bar = document.getElementById('reorder-save-bar');
                if (bar) bar.style.display = 'flex';
            }

            function hideBar() {
                const bar = document.getElementById('reorder-save-bar');
                if (bar) bar.style.display = 'none';
            }

            function initSortable() {
                if (sortableInstance) {
                    sortableInstance.destroy();
                }
                sortableInstance = Sortable.create(grid, {
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                    chosenClass: 'sortable-chosen',
                    dragClass: 'sortable-drag',
                    onEnd: function(evt) {
                        if (evt.oldIndex !== evt.newIndex) {
                            showBar();
                        }
                    }
                });
            }

            snapshotOrder();
            initSortable();

            window.saveOrder = async function() {
                const order = Array.from(grid.children).map(el => parseInt(el.dataset.id, 10));
                const bar = document.getElementById('reorder-save-bar');
                const saveBtn = bar.querySelector('.save-btn');
                const discardBtn = bar.querySelector('.discard-btn');
                const originalText = saveBtn.textContent;

                saveBtn.disabled = true;
                discardBtn.disabled = true;
                saveBtn.textContent = 'Saving…';

                try {
                    const resp = await fetch('{{ route("admin.galleries.reorder-images", $gallery) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ order: order }),
                    });

                    if (!resp.ok) {
                        throw new Error(`HTTP ${resp.status}`);
                    }

                    const data = await resp.json();
                    if (!data.success) {
                        throw new Error(data.message || 'Save failed');
                    }

                    hideBar();
                    snapshotOrder(); // New baseline
                    if (typeof toast === 'function') toast('Image order saved', 'success');
                    else if (window.toast) window.toast('Image order saved', 'success');
                } catch (err) {
                    alert('Could not save order: ' + err.message);
                } finally {
                    saveBtn.disabled = false;
                    discardBtn.disabled = false;
                    saveBtn.textContent = originalText;
                }
            };

            window.discardOrder = function() {
                grid.innerHTML = '';
                originalOrder.forEach(node => grid.appendChild(node));
                hideBar();
                initSortable();
            };
        })();
    </script>

<script nonce="@nonce">
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
const editVenueAccentColors = {
    'white-cube': '#e0e0e0', 'industrial-loft': '#8a7a50',
    'dark-museum': '#8b1a1a', 'zen-gallery': '#8b7355',
    'luxury-penthouse': '#c9a84c', 'cyber-gallery': '#00ffff',
    'sculpture-garden': '#4ade80', 'infinite-void': '#8b5cf6',
};

function selectEditVenue(card) {
    if (card.dataset.accessible !== 'true') {
        window.removeEventListener('beforeunload', window._dirtyHandler);
        window.removeEventListener('beforeunload', window._reorderHandler);
        window.location.href = '/pricing';
        return;
    }
    document.querySelectorAll('.venue-card-inner').forEach(el => {
        el.classList.remove('selected');
    });
    card.querySelector('.venue-card-inner').classList.add('selected');

    // Pause dirty tracking while we programmatically sync fields
    _pauseDirty = true;

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

    // Mark dirty (user intentionally changed venue) then re-enable tracking
    dirty = true;
    setTimeout(() => { _pauseDirty = false; }, 50);

    const slug = editSlugMap[card.dataset.venueId];
    const accent = editVenueAccentColors[slug] || '#8b5cf6';
    const bar = document.getElementById('edit-venue-info-bar');
    if (bar) {
        bar.style.display = 'flex';
        bar.style.borderColor = accent + '40';
        bar.style.background = accent + '10';
        document.getElementById('edit-venue-info-accent').style.background = accent;
        document.getElementById('edit-venue-info-accent').style.boxShadow = `0 0 8px ${accent}`;
        document.getElementById('edit-venue-info-name').textContent = card.querySelector('.venue-meta div:first-child')?.textContent?.trim() || '';
        document.getElementById('edit-venue-info-desc').textContent = editVenueDescriptions[slug] || '';
    }

    const descEl = document.getElementById('edit-venue-description');
    if (descEl) descEl.textContent = editVenueDescriptions[slug] || '';
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
const preselectedCard = document.querySelector('.venue-card-inner.selected');
if (preselectedCard) {
    const card = preselectedCard.closest('.edit-venue-card');
    const slug = editSlugMap[card?.dataset?.venueId];
    if (slug) {
        const descEl2 = document.getElementById('edit-venue-description');
        if (descEl2) descEl2.textContent = editVenueDescriptions[slug] || '';
        const accent = editVenueAccentColors[slug] || '#8b5cf6';
        const bar = document.getElementById('edit-venue-info-bar');
        if (bar) {
            bar.style.display = 'flex';
            bar.style.borderColor = accent + '40';
            bar.style.background = accent + '10';
            document.getElementById('edit-venue-info-accent').style.background = accent;
            document.getElementById('edit-venue-info-accent').style.boxShadow = `0 0 8px ${accent}`;
            document.getElementById('edit-venue-info-name').textContent = card?.querySelector('.venue-meta div:first-child')?.textContent?.trim() || '';
            document.getElementById('edit-venue-info-desc').textContent = editVenueDescriptions[slug] || '';
        }
    }
}
</script>
<script nonce="@nonce">
// Show inline save feedback next to the Update Settings button
function showSaveFeedback(message, isSuccess) {
    const fb = document.getElementById('save-feedback');
    const okIcon = document.getElementById('save-feedback-icon-ok');
    const errIcon = document.getElementById('save-feedback-icon-err');
    const text = document.getElementById('save-feedback-text');
    okIcon.classList.toggle('hidden', !isSuccess);
    errIcon.classList.toggle('hidden', isSuccess);
    text.textContent = message;
    text.className = isSuccess ? 'text-green-400' : 'text-red-400';
    fb.classList.remove('hidden');
    fb.classList.add('flex');
    clearTimeout(fb._hideTimer);
    fb._hideTimer = setTimeout(() => {
        fb.classList.add('hidden');
        fb.classList.remove('flex');
    }, 4000);
}
</script>

<!-- Custom "Unsaved changes" modal — replaces native browser dialog -->
<div id="unsaved-changes-modal"
     style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.65); backdrop-filter:blur(4px); align-items:center; justify-content:center;"
     data-click="closeUnsavedChangesModalIfBackdrop">
    <div style="background:#1f2937; border:1px solid #374151; border-radius:16px; padding:2rem; max-width:400px; width:90%; box-shadow:0 25px 60px rgba(0,0,0,0.5);">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:1rem;">
            <div style="width:40px; height:40px; border-radius:10px; background:rgba(245,158,11,0.15); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" fill="none" stroke="#f59e0b" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            </div>
            <div>
                <p style="font-size:1rem; font-weight:700; color:#f1f5f9; margin:0;">Unsaved changes</p>
                <p style="font-size:0.82rem; color:#94a3b8; margin:2px 0 0;">Your gallery settings have not been saved yet.</p>
            </div>
        </div>
        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:1.5rem;">
            <button data-click="closeUnsavedChangesModal"
                    style="background:#374151; border:none; color:#d1d5db; font-size:0.875rem; font-weight:600; padding:0.6rem 1.25rem; border-radius:8px; cursor:pointer;">
                Keep editing
            </button>
            <button id="unsaved-leave-btn"
                    style="background:linear-gradient(135deg,#ef4444,#dc2626); border:none; color:#fff; font-size:0.875rem; font-weight:600; padding:0.6rem 1.25rem; border-radius:8px; cursor:pointer;">
                Leave without saving
            </button>
        </div>
    </div>
</div>

</x-app-layout>