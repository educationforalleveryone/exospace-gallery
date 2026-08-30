@props([
    'href'        => '#',
    'icon',
    'label',
    'description' => null,
    'color'       => 'purple',
    'disabled'    => false,
])

@php
$palettes = [
    'purple' => 'bg-purple-600/10 border-purple-500/20 hover:bg-purple-600/20 hover:border-purple-500/40 text-purple-400',
    'blue'   => 'bg-blue-600/10 border-blue-500/20 hover:bg-blue-600/20 hover:border-blue-500/40 text-blue-400',
    'green'  => 'bg-green-600/10 border-green-500/20 hover:bg-green-600/20 hover:border-green-500/40 text-green-400',
    'amber'  => 'bg-amber-600/10 border-amber-500/20 hover:bg-amber-600/20 hover:border-amber-500/40 text-amber-400',
    'indigo' => 'bg-indigo-600/10 border-indigo-500/20 hover:bg-indigo-600/20 hover:border-indigo-500/40 text-indigo-400',
    'red'    => 'bg-red-600/10 border-red-500/20 hover:bg-red-600/20 hover:border-red-500/40 text-red-400',
];
$cls = $disabled
    ? 'bg-gray-800/40 border-gray-700 text-gray-700 cursor-not-allowed'
    : ($palettes[$color] ?? $palettes['purple']) . ' cursor-pointer transition-all duration-150';
@endphp

<a
    href="{{ $disabled ? '#' : $href }}"
    @if($disabled) data-click="noopDisabled" aria-disabled="true" @endif
    class="flex flex-col items-center text-center p-3.5 rounded-xl border {{ $cls }}"
>
    <div class="w-9 h-9 rounded-lg flex items-center justify-center mb-2.5 bg-white/10">
        <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
        </svg>
    </div>
    <span class="text-xs font-semibold text-gray-200 leading-tight">{{ $label }}</span>
    @if($description)
        <span class="text-xs text-gray-600 mt-0.5 leading-tight">{{ $description }}</span>
    @endif
</a>
