@props([
    'href',
    'icon',
    'label',
    'description' => null,
    'color'       => 'purple',
    'disabled'    => false,
    'onclick'     => null,
])

@php
$colors = [
    'purple' => 'bg-purple-600/10 border-purple-500/20 hover:bg-purple-600/20 hover:border-purple-500/50 text-purple-400',
    'blue'   => 'bg-blue-600/10 border-blue-500/20 hover:bg-blue-600/20 hover:border-blue-500/50 text-blue-400',
    'green'  => 'bg-green-600/10 border-green-500/20 hover:bg-green-600/20 hover:border-green-500/50 text-green-400',
    'amber'  => 'bg-amber-600/10 border-amber-500/20 hover:bg-amber-600/20 hover:border-amber-500/50 text-amber-400',
];
$cls = $disabled
    ? 'bg-gray-800/50 border-gray-700 text-gray-600 cursor-not-allowed opacity-50'
    : ($colors[$color] ?? $colors['purple']) . ' cursor-pointer transition-all duration-150';
@endphp

@if($disabled)
<div class="flex flex-col items-center text-center p-4 rounded-xl border {{ $cls }}">
@elseif($onclick)
<button onclick="{{ $onclick }}" class="flex flex-col items-center text-center p-4 rounded-xl border {{ $cls }} w-full">
@else
<a href="{{ $href }}" class="flex flex-col items-center text-center p-4 rounded-xl border {{ $cls }}">
@endif
    <div class="w-10 h-10 rounded-lg bg-current/10 flex items-center justify-center mb-3 opacity-80">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
        </svg>
    </div>
    <span class="text-sm font-semibold text-gray-200">{{ $label }}</span>
    @if($description)
        <span class="text-xs text-gray-500 mt-1">{{ $description }}</span>
    @endif
@if($disabled)
</div>
@elseif($onclick)
</button>
@else
</a>
@endif
