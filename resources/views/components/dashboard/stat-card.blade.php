@props([
    'label',
    'value',
    'icon',
    'color'     => 'purple',
    'trend'     => null,   // integer percent, positive/negative/null
    'trendLabel'=> null,
    'sub'       => null,
    'subColor'  => 'gray',
    'href'      => null,
    'badge'     => null,   // short badge string, e.g. 'Today'
])

@php
$colors = [
    'purple' => ['bg' => 'bg-purple-600',  'border' => 'hover:border-purple-500/60', 'glow' => 'hover:shadow-purple-900/30'],
    'indigo' => ['bg' => 'bg-indigo-600',  'border' => 'hover:border-indigo-500/60', 'glow' => 'hover:shadow-indigo-900/30'],
    'blue'   => ['bg' => 'bg-blue-600',    'border' => 'hover:border-blue-500/60',   'glow' => 'hover:shadow-blue-900/30'],
    'green'  => ['bg' => 'bg-green-600',   'border' => 'hover:border-green-500/60',  'glow' => 'hover:shadow-green-900/30'],
    'amber'  => ['bg' => 'bg-amber-600',   'border' => 'hover:border-amber-500/60',  'glow' => 'hover:shadow-amber-900/30'],
    'red'    => ['bg' => 'bg-red-600',     'border' => 'hover:border-red-500/60',    'glow' => 'hover:shadow-red-900/30'],
];
$c      = $colors[$color] ?? $colors['purple'];
$tag    = $href ? 'a' : 'div';

$trendUp   = $trend !== null && $trend > 0;
$trendDown = $trend !== null && $trend < 0;
$trendFlat = $trend !== null && $trend === 0;
@endphp

<{{ $tag }}
    @if($href) href="{{ $href }}" @endif
    class="relative bg-gray-800 overflow-hidden rounded-xl border border-gray-700/80 {{ $c['border'] }} {{ $c['glow'] }} shadow-lg hover:shadow-xl transition-all duration-200 {{ $href ? 'cursor-pointer' : '' }}"
>
    {{-- Subtle top accent line --}}
    <div class="absolute top-0 inset-x-0 h-px {{ $c['bg'] }} opacity-60"></div>

    <div class="p-5">
        <div class="flex items-start justify-between mb-3">
            <div class="{{ $c['bg'] }} w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm">
                <svg class="w-4.5 h-4.5 text-white" style="width:18px;height:18px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
                </svg>
            </div>
            <div class="flex items-center gap-1.5 flex-shrink-0">
                @if($badge)
                    <span class="text-xs bg-gray-700 text-gray-400 px-2 py-0.5 rounded-full font-medium">{{ $badge }}</span>
                @endif
                {{ $slot }}
            </div>
        </div>

        <div class="text-2xl font-bold text-gray-100 tabular-nums leading-none tracking-tight mb-1">{{ $value }}</div>
        <div class="text-sm text-gray-400 mb-2">{{ $label }}</div>

        <div class="flex items-center gap-2 min-h-[18px]">
            @if($trend !== null)
                @if($trendUp)
                    <span class="inline-flex items-center gap-0.5 text-xs font-semibold text-green-400">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 15l7-7 7 7"/></svg>
                        +{{ $trend }}%
                    </span>
                @elseif($trendDown)
                    <span class="inline-flex items-center gap-0.5 text-xs font-semibold text-red-400">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7"/></svg>
                        {{ $trend }}%
                    </span>
                @else
                    <span class="text-xs text-gray-500 font-medium">→ flat</span>
                @endif
                @if($trendLabel)
                    <span class="text-xs text-gray-600">{{ $trendLabel }}</span>
                @endif
            @elseif($sub)
                <span class="text-xs {{ match($subColor) { 'green' => 'text-green-400', 'red' => 'text-red-400', 'amber' => 'text-amber-400', default => 'text-gray-500' } }}">{{ $sub }}</span>
            @endif
        </div>
    </div>
</{{ $tag }}>
