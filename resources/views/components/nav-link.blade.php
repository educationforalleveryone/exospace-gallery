@props(['active'])

@php
// aria-current="page" on active links (WCAG 2.4.8 Location) — preserved
// from ITERATION-2. Active/hover accents now use the semantic `brand`
// token instead of raw purple-*.
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-brand-400 text-sm font-semibold leading-5 text-white focus:outline-none transition duration-150 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-400 hover:text-gray-200 hover:border-gray-600 focus:outline-none transition duration-150 ease-in-out';

$ariaCurrent = ($active ?? false) ? 'page' : null;
@endphp

{{-- ITERATION-1 FIX: tolerate direct view() renders where --}}
{{-- $attributes/$slot are not injected (see responsive-nav-link). --}}
@php
$attrHtml = 'class="' . $classes . '"' . ($ariaCurrent ? ' aria-current="' . $ariaCurrent . '"' : '');
if (isset($attributes)) {
    $attrHtml = $attributes->merge(['class' => $classes, 'aria-current' => $ariaCurrent])->toHtml();
}
@endphp

<a {!! $attrHtml !!}>{{ $slot ?? '' }}</a>
