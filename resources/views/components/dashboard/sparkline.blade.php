@props([
    'data',
    'label'    => 'Views last 7 days',
    'today'    => null,
    'trend'    => null,
    'href'     => null,
])

@php
$values = $data->values();
$days   = $data->keys();
$max    = max($values->max(), 1);
$total  = $values->sum();
$trendUp   = $trend !== null && $trend > 0;
$trendDown = $trend !== null && $trend < 0;
@endphp

<div>
    {{-- Header --}}
    <div class="flex items-start justify-between mb-4 gap-3">
        <div>
            <div class="text-sm font-medium text-gray-400 mb-0.5">{{ $label }}</div>
            <div class="flex items-baseline gap-2">
                <span class="text-2xl font-bold text-gray-100 tabular-nums">{{ number_format($total) }}</span>
                <span class="text-xs text-gray-500">total</span>
                @if($today !== null)
                    <span class="text-xs text-gray-600 ml-1">·</span>
                    <span class="text-xs font-semibold {{ $today > 0 ? 'text-brand-400' : 'text-gray-600' }}">
                        {{ number_format($today) }} today
                    </span>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
            @if($trend !== null)
                <span class="inline-flex items-center gap-0.5 text-xs font-semibold px-2 py-1 rounded-full
                    {{ $trendUp ? 'bg-emerald-500/10 text-emerald-400' : ($trendDown ? 'bg-red-500/10 text-red-400' : 'bg-gray-700 text-gray-500') }}">
                    @if($trendUp)
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 15l7-7 7 7"/></svg>
                        +{{ $trend }}% vs last 7d
                    @elseif($trendDown)
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7"/></svg>
                        {{ $trend }}% vs last 7d
                    @else
                        → flat vs last 7d
                    @endif
                </span>
            @endif
            @if($href)
                <a href="{{ $href }}" class="text-xs text-brand-400 hover:text-brand-300 transition font-medium">
                    Details →
                </a>
            @endif
        </div>
    </div>

    {{-- Bars --}}
    <div class="flex items-end gap-1.5" style="height:56px">
        @foreach($values as $i => $count)
        @php
            $pct    = $max > 0 ? round(($count / $max) * 100) : 0;
            $isLast = $i === $values->count() - 1;
        @endphp
        <div class="flex-1 flex flex-col items-center gap-0" title="{{ $days[$i] }}: {{ number_format($count) }} views">
            <div
                class="w-full rounded-t-sm transition-all duration-500 {{ $isLast ? 'bg-brand-500' : ($count > 0 ? 'bg-gray-600 hover:bg-gray-500' : 'bg-gray-700/50') }}"
                style="height:{{ max($pct, $count > 0 ? 8 : 3) }}%"
            ></div>
        </div>
        @endforeach
    </div>

    {{-- Day labels --}}
    <div class="flex gap-1.5 mt-1.5">
        @foreach($days as $i => $day)
        <div class="flex-1 text-center text-xs {{ $i === $days->count() - 1 ? 'text-brand-400 font-medium' : 'text-gray-600' }}">{{ $day }}</div>
        @endforeach
    </div>
</div>
