@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-purple-400 text-start text-base font-medium text-purple-300 bg-gray-900 focus:outline-none focus:text-purple-200 focus:bg-gray-950 focus:border-purple-500 transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-400 hover:text-gray-200 hover:bg-gray-700 hover:border-gray-500 focus:outline-none focus:text-gray-200 focus:bg-gray-700 focus:border-gray-500 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>