@props([
    'gallery',
    'stale' => false,   // live but 0 views after 3+ days
])

@php
// Cover is eagerly loaded as 'coverImage' if available, else we skip thumbnail
$coverPath = $gallery->relationLoaded('coverImage') && $gallery->coverImage
    ? $gallery->coverImage->path
    : null;
$isEmpty = ($gallery->images_count ?? 0) === 0;
@endphp

<div class="flex items-center gap-3 sm:gap-4 px-4 sm:px-6 py-3.5 hover:bg-white/[0.02] transition-colors group">

    {{-- Thumbnail --}}
    <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg overflow-hidden flex-shrink-0 bg-gray-700/80 border border-gray-700">
        @if($coverPath)
            <img src="{{ asset($coverPath) }}" alt="{{ $gallery->title ?: 'Gallery cover' }}" class="w-full h-full object-cover">
        @else
            <div class="w-full h-full flex items-center justify-center">
                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
        @endif
    </div>

    {{-- Meta --}}
    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 mb-0.5">
            <span class="text-sm font-semibold text-gray-100 truncate">{{ $gallery->title }}</span>
            {{-- Status dot --}}
            @if($gallery->is_active)
                <span class="w-1.5 h-1.5 rounded-full bg-green-400 flex-shrink-0" title="Live"></span>
            @else
                <span class="w-1.5 h-1.5 rounded-full bg-gray-600 flex-shrink-0" title="Draft"></span>
            @endif
            {{-- Health flags --}}
            @if($isEmpty && $gallery->is_active)
                <span class="hidden sm:inline-flex items-center gap-1 text-xs bg-red-900/40 text-red-400 border border-red-800/50 px-1.5 py-0.5 rounded-md flex-shrink-0">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01"/></svg>
                    No images
                </span>
            @elseif($stale)
                <span class="hidden sm:inline-flex items-center gap-1 text-xs bg-amber-900/30 text-amber-500 border border-amber-800/40 px-1.5 py-0.5 rounded-md flex-shrink-0">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    No views yet
                </span>
            @endif
        </div>
        <div class="flex items-center gap-2 sm:gap-3 text-xs text-gray-500 flex-wrap">
            <span>{{ $gallery->images_count }} {{ Str::plural('image', $gallery->images_count) }}</span>
            @if($gallery->view_count > 0)
                <span class="hidden sm:inline">·</span>
                <span class="hidden sm:inline">{{ number_format($gallery->view_count) }} views</span>
            @endif
            <span class="hidden sm:inline">·</span>
            <span class="hidden sm:inline">{{ $gallery->created_at->diffForHumans() }}</span>
        </div>
    </div>

    {{-- Actions — always visible on mobile, hover on desktop --}}
    <div class="flex items-center gap-1.5 sm:gap-2 flex-shrink-0 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity duration-150">
        @if($gallery->is_active)
        <a href="{{ route('gallery.view', $gallery->slug) }}"
           target="_blank"
           class="inline-flex items-center gap-1 text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 px-2.5 py-1.5 rounded-lg transition"
           title="View live">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
            <span class="hidden sm:inline">View</span>
        </a>
        <button
            data-click="dashboardShare" data-args='[{{ json_encode(route('gallery.view', $gallery->slug)) }},{{ json_encode($gallery->title) }}]'
            class="inline-flex items-center gap-1 text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 px-2.5 py-1.5 rounded-lg transition"
            title="Share">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
            <span class="hidden sm:inline">Share</span>
        </button>
        @endif
        <a href="{{ route('admin.galleries.edit', $gallery) }}"
           class="inline-flex items-center gap-1 text-xs bg-purple-700/30 hover:bg-purple-700/60 text-purple-300 px-2.5 py-1.5 rounded-lg transition"
           title="Edit">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            <span class="hidden sm:inline">Edit</span>
        </a>
    </div>

</div>
