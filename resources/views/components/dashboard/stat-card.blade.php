@props([
    'label',
    'value',
    'icon',
    'color'    => 'purple',
    'sub'      => null,
    'subColor' => 'gray',
    'href'     => null,
])

@php
$colors = [
    'purple' => ['icon' => 'bg-purple-600', 'border' => 'hover:border-purple-500'],
    'indigo' => ['icon' => 'bg-indigo-600', 'border' => 'hover:border-indigo-500'],
    'blue'   => ['icon' => 'bg-blue-600',   'border' => 'hover:border-blue-500'],
    'green'  => ['icon' => 'bg-green-600',  'border' => 'hover:border-green-500'],
    'amber'  => ['icon' => 'bg-amber-600',  'border' => 'hover:border-amber-500'],
];
$subColors = [
    'green' => 'text-green-400',
    'red'   => 'text-red-400',
    'gray'  => 'text-gray-500',
    'amber' => 'text-amber-400',
];
$c      = $colors[$color]   ?? $colors['purple'];
$subCls = $subColors[$subColor] ?? $subColors['gray'];
$tag    = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if($href) href="{{ $href }}" @endif
    class="bg-gray-800 overflow-hidden shadow-lg rounded-xl border border-gray-700 {{ $c['border'] }} transition {{ $href ? 'cursor-pointer group' : '' }}"
>
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="{{ $c['icon'] }} w-11 h-11 rounded-lg flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
                </svg>
            </div>
            {{ $slot }}
        </div>
        <div class="text-3xl font-bold text-gray-100 mb-1 tabular-nums">{{ $value }}</div>
        <div class="text-sm text-gray-400">{{ $label }}</div>
        @if($sub)
            <div class="text-xs mt-1 {{ $subCls }}">{{ $sub }}</div>
        @endif
    </div>
</{{ $tag }}>
