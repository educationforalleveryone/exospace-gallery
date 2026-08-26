@props(['active'])

@php
// ITERATION-2 (AUDIT-P1-2.6): Added aria-current="page" on active links.
// Previously the active state was communicated only via color (purple
// border-bottom). Screen-reader users had no programmatic indicator of
// the current page. WCAG 2.4.8 (Location) recommends `aria-current="page"`.
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-purple-400 text-sm font-semibold leading-5 text-white focus:outline-none transition duration-150 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-400 hover:text-gray-200 hover:border-gray-600 focus:outline-none transition duration-150 ease-in-out';

$ariaCurrent = ($active ?? false) ? 'page' : null;
@endphp

{{-- ITERATION-1 FIX: $attributes/$slot only exist when rendered as a --}}
{{-- real <x-nav-link> component. Direct view('components.nav-link') --}}
{{-- renders (tests, previews) crashed with "Undefined variable $slot". --}}
{{-- Build the attribute HTML explicitly so both paths work. --}}
@php
$attrHtml = 'class="' . $classes . '"' . ($ariaCurrent ? ' aria-current="' . $ariaCurrent . '"' : '');
if (isset($attributes)) {
    $attrHtml = $attributes->merge(['class' => $classes, 'aria-current' => $ariaCurrent])->toHtml();
}
@endphp

<a {!! $attrHtml !!}>
    {{ $slot ?? '' }}
</a>