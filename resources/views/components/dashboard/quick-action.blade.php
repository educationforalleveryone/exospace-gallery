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
    'purple' => 'bg-brand-600/10 border-brand-500/20 hover:bg-brand-600/20 hover:border-brand-500/40 text-brand-400',
    'blue'   => 'bg-blue-600/10 border-blue-500/20 hover:bg-blue-600/20 hover:border-blue-500/40 text-blue-400',
    'green'  => 'bg-emerald-600/10 border-emerald-500/20 hover:bg-emerald-600/20 hover:border-emerald-500/40 text-emerald-400',
    'amber'  => 'bg-amber-600/10 border-amber-500/20 hover:bg-amber-600/20 hover:border-amber-500/40 text-amber-400',
    'indigo' => 'bg-brand-600/10 border-brand-500/20 hover:bg-brand-600/20 hover:border-brand-500/40 text-brand-400',
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
