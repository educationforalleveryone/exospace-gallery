@php
    /**
     * Single slider row for the Live Preview panel.
     *
     * Vars:
     *   $id             string  — control id (also the JSON key)
     *   $label          string  — human label
     *   $unit           string  — unit suffix (e.g. "m", "")
     *   $min, $max, $step
     *   $value          number  — current effective value
     *   $default        number  — venue default (used for the reset button)
     *   $group          string  — 'visual_config' | 'material_config' | 'post_fx'
     *   $requiresReload bool    — true for structural changes (wall_height)
     *   $hint           string  — one-line textual explanation
     *   $hintSvg        string  — key for the inline SVG mockup
     *
     * Renders a label row + slider row + reset button + hover hint popover.
     * The parent JS reads data-lp-* attributes to wire up event handlers.
     */
    $requiresReload = $requiresReload ?? false;
@endphp

<div class="lp-control" data-lp-control-wrapper="{{ $id }}">
    <div class="flex items-center justify-between mb-1">
        <label class="text-xs font-medium text-gray-300 flex items-center gap-1.5 cursor-help" data-lp-hint-trigger="{{ $id }}">
            {{ $label }}
            @if($requiresReload)
                <span class="text-[9px] uppercase tracking-wider text-amber-400 font-bold" title="Changing this requires the preview to reload">⟳</span>
            @endif
            <svg class="w-3 h-3 text-gray-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
        </label>
        <div class="flex items-center gap-1.5">
            <span class="text-xs text-gray-400 tabular-nums" data-lp-value-for="{{ $id }}">{{ $value }}</span>
            @if(!empty($unit))<span class="text-[10px] text-gray-500">{{ $unit }}</span>@endif
            <button type="button" data-lp-reset-for="{{ $id }}"
                    class="text-[10px] text-gray-500 hover:text-purple-300 transition" title="Reset to venue default">↺</button>
        </div>
    </div>

    <input type="range"
           data-lp-control
           data-lp-key="{{ $id }}"
           data-lp-group="{{ $group }}"
           data-lp-default="{{ $default }}"
           data-lp-requires-reload="{{ $requiresReload ? 'true' : 'false' }}"
           min="{{ $min }}" max="{{ $max }}" step="{{ $step }}"
           value="{{ $value }}"
           class="w-full h-1.5 bg-gray-700 rounded-full appearance-none cursor-pointer accent-purple-500" />

    {{-- Hover hint popover — shows on hover/focus of the label --}}
    <div data-lp-hint-popover="{{ $id }}"
         class="hidden absolute z-50 mt-1 w-64 bg-gray-900 border border-gray-700 rounded-lg shadow-xl p-3 text-xs text-gray-300">
        <div class="flex items-start gap-2">
            {{-- SVG mini-mockup showing the effect direction --}}
            <div class="flex-shrink-0 w-16 h-12 bg-gray-800 rounded border border-gray-700 overflow-hidden">
                @switch($hintSvg)
                    @case('wall_height')
                        <svg viewBox="0 0 64 48" class="w-full h-full"><rect x="6" y="8" width="52" height="32" fill="none" stroke="#a78bfa" stroke-width="1.5"/><rect x="6" y="4" width="52" height="4" fill="#a78bfa" opacity="0.3"/><rect x="6" y="40" width="52" height="4" fill="#a78bfa" opacity="0.3"/></svg>
                        @break
                    @case('ambient')
                        <svg viewBox="0 0 64 48" class="w-full h-full"><rect width="64" height="48" fill="#1f2937"/><rect x="20" y="14" width="24" height="20" fill="#fff" opacity="0.3"/><rect x="20" y="14" width="24" height="20" fill="#fff" opacity="0.5"/></svg>
                        @break
                    @case('spot')
                        <svg viewBox="0 0 64 48" class="w-full h-full"><rect width="64" height="48" fill="#0a0a0a"/><polygon points="32,4 18,40 46,40" fill="#fef3c7" opacity="0.4"/><polygon points="32,4 24,40 40,40" fill="#fef3c7" opacity="0.7"/></svg>
                        @break
                    @case('exposure')
                        <svg viewBox="0 0 64 48" class="w-full h-full"><defs><linearGradient id="exp-{{ $id }}" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="#1f2937"/><stop offset="50%" stop-color="#9ca3af"/><stop offset="100%" stop-color="#f9fafb"/></linearGradient></defs><rect width="64" height="48" fill="url(#exp-{{ $id }})"/></svg>
                        @break
                    @case('fog')
                        <svg viewBox="0 0 64 48" class="w-full h-full"><defs><linearGradient id="fog-{{ $id }}" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="#1f2937" stop-opacity="0"/><stop offset="100%" stop-color="#1f2937" stop-opacity="1"/></linearGradient></defs><rect width="64" height="48" fill="#9ca3af"/><rect width="64" height="48" fill="url(#fog-{{ $id }})"/></svg>
                        @break
                    @case('roughness')
                        <svg viewBox="0 0 64 48" class="w-full h-full"><rect width="32" height="48" fill="#6b7280"/><rect x="32" width="32" height="48" fill="#e5e7eb"/></svg>
                        @break
                    @case('metalness')
                        <svg viewBox="0 0 64 48" class="w-full h-full"><defs><linearGradient id="met-{{ $id }}" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="#1f2937"/><stop offset="50%" stop-color="#9ca3af"/><stop offset="100%" stop-color="#1f2937"/></linearGradient></defs><rect width="32" height="48" fill="#6b7280"/><rect x="32" width="32" height="48" fill="url(#met-{{ $id }})"/></svg>
                        @break
                    @case('bloom')
                        <svg viewBox="0 0 64 48" class="w-full h-full"><rect width="64" height="48" fill="#0a0a0a"/><circle cx="32" cy="24" r="6" fill="#fef3c7"/><circle cx="32" cy="24" r="12" fill="#fef3c7" opacity="0.3"/><circle cx="32" cy="24" r="18" fill="#fef3c7" opacity="0.1"/></svg>
                        @break
                    @case('vignette')
                        <svg viewBox="0 0 64 48" class="w-full h-full"><defs><radialGradient id="vig-{{ $id }}"><stop offset="0%" stop-color="#9ca3af" stop-opacity="0"/><stop offset="100%" stop-color="#000" stop-opacity="1"/></radialGradient></defs><rect width="64" height="48" fill="#9ca3af"/><rect width="64" height="48" fill="url(#vig-{{ $id }})"/></svg>
                        @break
                    @default
                        <svg viewBox="0 0 64 48" class="w-full h-full"><rect width="64" height="48" fill="#374151"/></svg>
                @endswitch
            </div>
            <div class="flex-1">{{ $hint }}</div>
        </div>
        <div class="mt-2 pt-2 border-t border-gray-700 text-[10px] text-gray-500">
            Venue default: <span class="text-gray-400 tabular-nums">{{ $default }}</span>{{ !empty($unit) ? ' '.$unit : '' }}
        </div>
    </div>
</div>

<style>
    /* Show the hint popover on hover/focus of the label trigger */
    [data-lp-hint-trigger]:hover ~ [data-lp-hint-popover],
    [data-lp-hint-trigger]:focus ~ [data-lp-hint-popover],
    [data-lp-hint-popover]:hover {
        display: block !important;
    }
    /* Make the wrapper position-relative so the absolute popover anchors correctly */
    .lp-control { position: relative; }
    [data-lp-hint-popover] { left: 0; top: 100%; }
</style>
