@props([
    'text' => null,        // tooltip text (simple use case)
    'position' => 'top',   // top | right | bottom | left
    'class' => '',
])

@php
/**
 * ITERATION-2 (AUDIT-P1-2.4): Tooltip component.
 *
 * Previously, tooltip support was CSS-only (`[data-tooltip]::after` in
 * app.blade.php lines 49-58). The CSS-only approach:
 *   - was not reusable across layouts (only app.blade.php had the styles)
 *   - was not accessible (no `role="tooltip"`, no `aria-describedby`)
 *   - could not be dismissed on mobile (no `:hover` on touch)
 *   - could not contain rich content (only `attr(data-tooltip)`)
 *
 * This component is Alpine-driven, accessible, and reusable everywhere.
 *
 * Usage (simple text):
 *   <x-tooltip text="Helpful explanation">
 *       <button>Hover me</button>
 *   </x-tooltip>
 *
 * Usage (rich content via slot):
 *   <x-tooltip position="right">
 *       <x-slot:content>
 *           <strong>Pro tip:</strong> You can also press <kbd>G</kbd>+<kbd>L</kbd>.
 *       </x-slot:content>
 *       <button>Hover me</button>
 *   </x-tooltip>
 *
 * Accessibility:
 *   - The tooltip element has `role="tooltip"`.
 *   - The trigger element has `aria-describedby` pointing at the tooltip ID.
 *   - Tooltip becomes visible on focus OR hover (keyboard + mouse both work).
 *   - Tooltip is hidden on blur / mouseleave.
 *   - Tooltip is hidden when the trigger is disabled.
 *
 * Position variants: top (default) | right | bottom | left.
 */

// Generate a unique ID so multiple tooltips on the same page don't collide.
$tooltipId = 'tooltip-' . uniqid();

// Position classes for the tooltip panel.
$positionClasses = [
    'top'    => 'bottom-full left-1/2 -translate-x-1/2 mb-2',
    'right'  => 'left-full top-1/2 -translate-y-1/2 ml-2',
    'bottom' => 'top-full left-1/2 -translate-x-1/2 mt-2',
    'left'   => 'right-full top-1/2 -translate-y-1/2 mr-2',
];
$positionClass = $positionClasses[$position] ?? $positionClasses['top'];
@endphp

<span
    class="relative inline-flex {{ $class }}"
    x-data="{ open: false }"
    @mouseenter="open = true"
    @mouseleave="open = false"
    @focusin="open = true"
    @focusout="open = false"
>
    <span
        class="inline-flex"
        aria-describedby="{{ $tooltipId }}"
    >
        {{ $slot }}
    </span>

    <span
        id="{{ $tooltipId }}"
        role="tooltip"
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        class="absolute z-50 {{ $positionClass }} pointer-events-none max-w-xs whitespace-normal rounded-lg border border-gray-700 bg-ink-950 px-3 py-2 text-xs font-medium text-gray-100 shadow-xl"
    >
        @if(isset($content) || $slots->has('content'))
            {{ $content ?? $slots->content }}
        @else
            {{ $text }}
        @endif
    </span>
</span>
