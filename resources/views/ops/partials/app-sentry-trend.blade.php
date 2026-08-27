{{-- OpsCenter (Iteration 8): the per-application Sentry trend cell for the
     Applications table — a compact pure-SVG 24-bar sparkline mirroring the
     conventions of partials/sentry-trend.blade.php (no JS, no npm, no
     chart library — the discovery audit's "pure Blade UI" rule holds).

     Receives:
       $trend     the SentryApiClient::trendFor() fail-soft shape
                  (configured/error/series/total/peak/peak_hour)
       $mapped    bool — whether the application has a mapping at all

     Rendering contract (mirrors the digest's "omitted is not broken"):
       unmapped                  → muted dash “not mapped” tooltip (informational)
       mapped + API unconfigured → muted dash “token not configured” tooltip —
                                   NOT a zero: silence is not “no errors”
       configured + error        → honest amber “API error” + title tooltip
       configured + series       → sparkline + total + peak tooltip
       configured + zero events  → sparkline renders flat + “0” — a mapped
                                   project with no errors is a RESULT. --}}

@php
    $trend = $trend ?? [];
    $mapped = (bool) ($mapped ?? false);
    $usable = ! empty($trend['configured'])
        && empty($trend['error'])
        && ! empty($trend['series']);
    $fetched = ! empty($trend['configured']);
@endphp

@if($usable)
    @php
        $series = $trend['series'];
        $max = max(1, (int) $trend['peak']);
        $n = count($series);
        $total = (int) $trend['total'];
        // Compact geometry: 96×24 viewBox, 4px pitch for 24 bars.
        $pitch = $n > 0 ? min(4.0, 96 / $n) : 4.0;
        $barW = max(0.8, $pitch * 0.66);
        $offset = (96 - ($pitch * $n)) / 2;
    @endphp
    <div class="flex items-center gap-2" title="Sentry 24 h: {{ number_format($total) }} events, peak {{ (int) $trend['peak'] }}/h around {{ $trend['peak_hour'] ?? 'unknown' }}">
        <svg viewBox="0 0 96 24" preserveAspectRatio="none" class="w-16 h-6 rounded bg-slate-950/60 border border-slate-800 shrink-0" role="img" aria-label="Hourly Sentry error events for {{ $total }} total, peak {{ (int) $trend['peak'] }} per hour">
            @foreach($series as $i => $point)
                @php
                    $count = (int) $point['count'];
                    $h = $count > 0 ? max(1.0, ($count / $max) * 21) : 0.5;
                    $x = $offset + ($pitch * $i);
                    $y = 24 - $h;
                    $isPeak = $count > 0 && $count === (int) $trend['peak'];
                @endphp
                <rect x="{{ round($x, 2) }}" y="{{ round($y, 2) }}" width="{{ round($barW, 2) }}" height="{{ round($h, 2) }}"
                      class="{{ $isPeak ? 'fill-amber-400/90' : 'fill-emerald-400/70' }}">
                    <title>{{ date('H:i', (int) $point['ts']) }} UTC — {{ $count }} event{{ $count === 1 ? '' : 's' }}</title>
                </rect>
            @endforeach
        </svg>
        <span class="text-xs font-mono {{ $total > 0 ? 'text-slate-200' : 'text-slate-500' }}">{{ number_format($total) }}</span>
    </div>
@elseif(! empty($trend['configured']) && ! empty($trend['error']))
    {{-- Honest degradation: the mapped project's stats call failed (bad
         slug, missing scope, rate limit) — one amber word, reason in the
         tooltip. Silence would read exactly like "no errors". --}}
    <span class="text-[10px] text-amber-400/90 font-semibold" title="{{ $trend['error'] }}">API error</span>
@elseif($fetched)
    {{-- Configured, mapped, no error, but no usable series either — the
         practical edge; treat as a quiet day rather than a failure. --}}
    <span class="text-xs font-mono text-slate-500">0</span>
@elseif($mapped)
    {{-- Mapped, but the API token is not configured: the mapping can be
         saved ahead of the token, and the cell says so instead of
         claiming a zero-error day. --}}
    <span class="text-[10px] text-slate-600" title="Mapped, but SENTRY_API_TOKEN is not configured — no trend until it is set">—</span>
@else
    <span class="text-[10px] text-slate-600" title="Map this application to a Sentry project below (super-admin)">—</span>
@endif
