@props([
    'variant' => 'text',  // text | row | card | chart | avatar | button
    'class' => '',
    'count' => 1,         // render N skeleton items (for repeating patterns)
])

@php
$baseClass = 'animate-shimmer rounded-md bg-gradient-to-r from-surface-900 via-ink-800 to-surface-900 bg-[length:200%_100%]';

$variantClasses = [
    'text'   => 'h-3 w-full',
    'row'    => 'h-10 w-full rounded-lg',
    'card'   => 'h-24 w-full rounded-xl',
    'chart'  => 'h-48 w-full rounded-xl',
    'avatar' => 'h-10 w-10 rounded-full',
    'button' => 'h-9 w-24 rounded-lg',
];

$variantClass = $variantClasses[$variant] ?? $variantClasses['text'];
$finalClass = $baseClass . ' ' . $variantClass . ' ' . $class;

if ($variant === 'text' && !str_contains($class, 'w-')) {
    $finalClass .= ' w-3/4';
}
@endphp

@if((int) $count > 1)
    <div class="flex flex-col gap-3" role="status" aria-label="Loading">
        @for($i = 0; $i < (int) $count; $i++)
            <div class="{{ $finalClass }}"></div>
        @endfor
        <span class="sr-only">Loading…</span>
    </div>
@else
    <div class="{{ $finalClass }}" role="status" aria-label="Loading">
        <span class="sr-only">Loading…</span>
    </div>
@endif
