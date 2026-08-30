{{-- OpsCenter (Iteration 6): the Sentry error-trend sparkline — 24 hourly
     buckets from the events-stats endpoint, rendered as a pure inline-SVG
     bar chart. NO JavaScript, NO npm dependency, NO chart library: the
     discovery audit's "pure Blade UI" rule holds. Receives the $sentryTrend
     array from SentryApiClient::trend() (fail-soft shape):
       configured=false → renders nothing (tile shows its not-configured note)
       error            → one honest line, headlines above still render
       series           → the bars; total/peak/peak_hour caption underneath
     Bars: 2px wide with 3px pitch inside a 120×36 viewBox, stretched to
     the tile width via preserveAspectRatio="none". Peak bar highlighted
     (amber); quiet hours slate; zero-count hours get a 1px baseline stub
     so the timeline itself stays visible. --}}

@php
    $trend = $sentryTrend ?? [];
    $usable = ! empty($trend['configured'])
        && empty($trend['error'])
        && ! empty($trend['series']);
@endphp

@if($usable)
    @php
        $series = $trend['series'];
        $max = max(1, (int) $trend['peak']); // avoid divide-by-zero on an all-zero day
        $n = count($series);
        // Pitch: 120 units across N buckets (min 3 so 24 bars = 72/120 width
        // — centered; single buckets don't stretch absurdly).
        $pitch = $n > 0 ? min(5.0, 120 / $n) : 5.0;
        $barW = max(1.0, $pitch * 0.66);
        $offset = (120 - ($pitch * $n)) / 2;
        $peakTs = null;
        foreach ($series as $point) {
            if ((int) $point['count'] === (int) $trend['peak']) {
                $peakTs = (int) $point['ts'];
                break;
            }
        }
    @endphp
    <div class="mt-3">
        <div class="flex items-center justify-between mb-1">
            <span class="text-xs uppercase tracking-wider text-slate-500">Error volume — last 24 h</span>
            <span class="text-xs text-slate-500 font-mono">
                {{ number_format((int) $trend['total']) }} events
                @if((int) $trend['peak'] > 0) · peak {{ number_format((int) $trend['peak']) }}/h @endif
            </span>
        </div>
        <svg viewBox="0 0 120 36" preserveAspectRatio="none" class="w-full h-9 rounded bg-slate-950/60 border border-slate-800" role="img" aria-label="Hourly Sentry error events for the last 24 hours: {{ (int) $trend['total'] }} total, peak {{ (int) $trend['peak'] }} per hour">
            @foreach($series as $i => $point)
                @php
                    $count = (int) $point['count'];
                    $h = $count > 0 ? max(1.5, ($count / $max) * 32) : 0.6;
                    $x = $offset + ($pitch * $i);
                    $y = 36 - $h;
                    $isPeak = $count > 0 && $count === (int) $trend['peak'];
                    $isLast = $i === $n - 1;
                @endphp
                <rect x="{{ round($x, 2) }}" y="{{ round($y, 2) }}" width="{{ round($barW, 2) }}" height="{{ round($h, 2) }}"
                      class="{{ $isPeak ? 'fill-amber-400/90' : ($isLast ? 'fill-emerald-400/80' : 'fill-slate-500/70') }}">
                    <title>{{ date('H:i', (int) $point['ts']) }} UTC — {{ $count }} event{{ $count === 1 ? '' : 's' }}</title>
                </rect>
            @endforeach
        </svg>
        <div class="flex items-center justify-between mt-1">
            <span class="text-[9px] text-slate-600">-24 h</span>
            @if((int) $trend['peak'] > 0 && $peakTs !== null)
                <span class="text-[9px] text-amber-500/80">peak {{ date('H:i', $peakTs) }} UTC</span>
            @endif
            <span class="text-[9px] text-slate-600">now</span>
        </div>
    </div>
@elseif(! empty($trend['configured']) && ! empty($trend['error']))
    {{-- Honest degradation: the trend endpoint failed (e.g. token without
         event:read scope) — say so in one line, never hide the headlines. --}}
    <p class="text-xs text-slate-600 mt-3" title="{{ $trend['error'] }}">Error trend unavailable — {{ \Illuminate\Support\Str::limit($trend['error'], 80) }}</p>
@endif
