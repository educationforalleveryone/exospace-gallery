@props([
    'text'     => '',      // tooltip content (plain text, escaped on render)
    'position' => 'top',   // top | right | bottom | left
])

@php

$positionClasses = [
    'top'    => 'bottom-full left-1/2 -translate-x-1/2 mb-2',
    'right'  => 'left-full top-1/2 -translate-y-1/2 ml-2',
    'bottom' => 'top-full left-1/2 -translate-x-1/2 mt-2',
    'left'   => 'right-full top-1/2 -translate-y-1/2 mr-2',
];

$positionClass = $positionClasses[$position] ?? $positionClasses['top'];

$tooltipId = 'tooltip-' . uniqid();

$triggerClasses = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/80 focus-visible:ring-offset-2 focus-visible:ring-offset-ink-900 rounded-sm';

// Tolerate direct view() renders where $attributes/$slot are not
// injected (same contract as nav-link / responsive-nav-link).
$triggerAttrs = 'tabindex="0" class="' . $triggerClasses . '"';
if (isset($attributes)) {
    $triggerAttrs = $attributes->merge([
        'class'    => $triggerClasses,
        'tabindex' => '0',
    ])->toHtml();
}
@endphp

<span class="relative inline-flex" x-data="{ open: false }">
    {{-- Trigger is keyboard-focusable so sighted keyboard users get the tip --}}
    <span {!! $triggerAttrs !!}
          x-on:focusin="open = true"
          x-on:focusout="open = false"
          x-on:mouseenter="open = true"
          x-on:mouseleave="open = false"
          x-on:keydown.escape.window="open = false"
          aria-describedby="{{ $tooltipId }}">{{ $slot ?? '' }}</span>

    {{-- Opacity-only transition: a scale transform here would fight the --}}
    {{-- translate-* centering classes on the bubble itself. --}}
    <span x-cloak
          x-show="open"
          role="tooltip"
          id="{{ $tooltipId }}"
          x-transition:enter="transition ease-out duration-150"
          x-transition:enter-start="opacity-0"
          x-transition:enter-end="opacity-100"
          x-transition:leave="transition ease-in duration-100"
          x-transition:leave-start="opacity-100"
          x-transition:leave-end="opacity-0"
          class="pointer-events-none absolute z-[55] w-max max-w-xs rounded-md bg-gray-800 px-2.5 py-1.5 text-xs font-medium leading-snug text-gray-100 text-start shadow-lg ring-1 ring-gray-700/60 {{ $positionClass }}">{{ $text }}</span>
</span>
