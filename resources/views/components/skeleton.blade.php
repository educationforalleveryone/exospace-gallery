@props([
    'variant' => 'text',  // text | row | card | chart | avatar | button
    'class' => '',
    'count' => 1,         // render N skeleton items (for repeating patterns)
])

@php
/**
 * ITERATION-2 (AUDIT-P1-2.2): Skeleton component.
 *
 * Previously, the analytics page had 40+ lines of hand-coded `animate-pulse`
 * divs. Now any page can use <x-skeleton variant="card" /> to render a
 * placeholder that matches the visual weight of the real content.
 *
 * Variants:
 *   - text     — a single line of placeholder text (default width: full)
 *   - row      — a table/list row (height matches a typical table row)
 *   - card     — a stat card (small square, ~96px tall)
 *   - chart    — a chart placeholder (wider, taller, with a "line" hint)
 *   - avatar   — a circular avatar (used in member lists, comments)
 *   - button   — a button-shaped placeholder
 *
 * Pass `count` to repeat the skeleton N times (e.g. for a list of 5 rows).
 *
 * The shimmer uses the `animate-shimmer` keyframe defined in tailwind.config.js
 * with a gradient background that slides horizontally.
 */
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

// For the `text` variant, default to a slightly-shorter width unless the
// caller overrides it via `class="w-72"` etc.
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
