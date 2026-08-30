@props(['active'])

@php
// aria-current="page" on active links (WCAG 2.4.8 Location) — preserved.
// Accents use the semantic `brand` token.
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2.5 border-l-4 border-brand-400 text-start text-sm font-medium text-brand-300 bg-ink-900/60 focus:outline-none focus:text-brand-200 focus:bg-ink-900 focus:border-brand-500 transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2.5 border-l-4 border-transparent text-start text-sm font-medium text-gray-400 hover:text-gray-200 hover:bg-white/[0.04] hover:border-gray-600 focus:outline-none focus:text-gray-200 focus:bg-white/[0.04] focus:border-gray-500 transition duration-150 ease-in-out';

$ariaCurrent = ($active ?? false) ? 'page' : null;
@endphp

{{-- Tolerate direct view() renders where $attributes/$slot are not injected. --}}
@php
$attrHtml = 'class="' . $classes . '"' . ($ariaCurrent ? ' aria-current="' . $ariaCurrent . '"' : '');
if (isset($attributes)) {
    $attrHtml = $attributes->merge(['class' => $classes, 'aria-current' => $ariaCurrent])->toHtml();
}
@endphp

<a {!! $attrHtml !!}>{{ $slot ?? '' }}</a>
