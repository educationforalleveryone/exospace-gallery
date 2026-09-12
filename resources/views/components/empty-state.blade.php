@props([
    'icon' => null,        // SVG path data string OR named icon (gallery/artist/event/image/analytics/error)
    'title' => null,
    'description' => null,
    'action' => null,     // slot for a CTA button/link
    'compact' => false,   // smaller padding for inline use (inside cards)
])

@php

$namedIcons = [
    'gallery' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 5a1 1 0 011-1h4v16H5a1 1 0 01-1-1V5z M10 4h4v16h-4V4z M15 4h4a1 1 0 011 1v14a1 1 0 01-1 1h-4V4z"/>',
    'artist' => '<circle cx="12" cy="8" r="4" stroke-width="1.5"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
    'event' => '<rect x="3" y="5" width="18" height="16" rx="2" stroke-width="1.5"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9h18M8 3v4M16 3v4"/>',
    'image' => '<rect x="3" y="3" width="18" height="18" rx="2" stroke-width="1.5"/><circle cx="9" cy="9" r="2" stroke-width="1.5"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 15l-5-5L5 21"/>',
    'analytics' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3v18h18M7 14l3-3 3 3 4-5"/>',
    'error' => '<circle cx="12" cy="12" r="9" stroke-width="1.5"/><path stroke-linecap="round" stroke-width="1.5" d="M12 8v4m0 4h.01"/>',
    'search' => '<circle cx="11" cy="11" r="7" stroke-width="1.5"/><path stroke-linecap="round" stroke-width="1.5" d="M21 21l-4-4"/>',
];

// Default to the "gallery" icon if none provided.
$iconContent = $namedIcons[$icon] ?? $namedIcons['gallery'];

// If the caller passed a raw SVG path string (not a named icon), use it directly.
if ($icon !== null && !isset($namedIcons[$icon])) {
    $iconContent = $icon;
}

$padding = $compact ? 'py-8 px-4' : 'py-12 px-6';
@endphp

<div class="flex flex-col items-center justify-center text-center {{ $padding }}" role="status">
    <div class="mb-4 rounded-xl bg-gradient-to-br from-brand-500/10 to-brand-700/5 p-4 ring-1 ring-inset ring-brand-500/20">
        <svg class="w-10 h-10 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            {!! $iconContent !!}
        </svg>
    </div>
    @if($title)
        <h3 class="text-base font-semibold text-gray-100 mb-1.5">{{ $title }}</h3>
    @endif
    @if($description)
        <p class="text-sm text-gray-400 max-w-sm leading-relaxed">{{ $description }}</p>
    @endif
    {{-- ITERATION-1 FIX: `$slots` does not exist in Blade components --}}
    {{-- (named slots arrive as their own variables — here $action, --}}
    {{-- already declared in @props with a null default). The old --}}
    {{-- $slots->has() call threw "Undefined variable $slots" whenever --}}
    {{-- this component rendered. --}}
    @if(!empty($action))
        <div class="mt-5">
            {{ $action }}
        </div>
    @endif
    <span class="sr-only">{{ $title ?? 'Empty' }}</span>
</div>
