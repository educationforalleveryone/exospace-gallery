@props(['data', 'label' => 'Views last 7 days'])

@php
$max    = max($data->values()->max(), 1);
$total  = $data->sum();
$days   = $data->keys();
$values = $data->values();
@endphp

<div>
    <div class="flex items-center justify-between mb-3">
        <span class="text-sm font-medium text-gray-400">{{ $label }}</span>
        <span class="text-sm font-bold text-gray-100 tabular-nums">{{ number_format($total) }} total</span>
    </div>
    <div class="flex items-end gap-1 h-12">
        @foreach($values as $i => $count)
        @php
            $pct   = $max > 0 ? round(($count / $max) * 100) : 0;
            $today = $i === $values->count() - 1;
        @endphp
        <div class="flex-1 flex flex-col items-center gap-1" title="{{ $days[$i] }}: {{ $count }}">
            <div
                class="w-full rounded-t {{ $today ? 'bg-purple-500' : 'bg-gray-600' }} transition-all duration-300"
                style="height: {{ max($pct, 4) }}%"
            ></div>
        </div>
        @endforeach
    </div>
    <div class="flex gap-1 mt-1">
        @foreach($days as $day)
        <div class="flex-1 text-center text-xs text-gray-600">{{ $day }}</div>
        @endforeach
    </div>
</div>
