{{-- OpsCenter (Iteration 9): the weekly review's long memory — an 8-week
     error-volume strip rendered from ops_review_snapshots (one row per
     actual review delivery, latest per week). Same pure-Blade UI rules as
     every other OpsCenter chart: inline SVG, NO JavaScript, NO npm, NO
     chart library.

     Receives $snapshots: array<int, OpsReviewSnapshot> ordered oldest →
     newest (from OpsWeeklyReviewService::recentSnapshots()), already
     fail-soft — an unreadable table arrives as [] and renders the honest
     "accumulating" note, never a broken page.

     Bars: one per week, height = metrics.errors.total. Peak week amber,
     most recent week emerald, others slate — the same color language as
     the 24-hour Sentry sparkline. Every bar carries a <title> tooltip
     with the week's numbers (errors / incidents / MTTR / trigger).

     The honest-cold-start note is the point, not a fallback: a control
     plane that pretended to remember weeks it never recorded would be
     lying with a chart. --}}

@php
    $weeks = collect($snapshots ?? [])->filter(fn ($s) => $s instanceof \App\Ops\Models\OpsReviewSnapshot)->values();
    $bars = $weeks->map(fn ($s) => [
        'label' => $s->week_start->format('M j'),
        'end' => $s->week_end->format('M j'),
        'total' => (int) ($s->metrics['errors']['total'] ?? 0),
        'incidents' => (int) ($s->metrics['incidents']['opened'] ?? 0),
        'mttr' => $s->metrics['incidents']['mttr_minutes'] ?? null,
        'trigger' => (string) $s->trigger,
    ]);
@endphp

<div class="rounded-lg border border-slate-800 bg-slate-900/40 px-4 py-3">
    <div class="flex items-center justify-between mb-1">
        <span class="text-[10px] uppercase tracking-wider text-slate-500">Errors by week — from review snapshots</span>
        @if($bars->isNotEmpty())
            <span class="text-[10px] text-slate-500 font-mono">{{ $bars->count() }} week{{ $bars->count() === 1 ? '' : 's' }} recorded</span>
        @endif
    </div>

    @if($bars->count() >= 2)
        @php
            $max = max(1, $bars->max('total')); // all-zero memory still draws a baseline
            $n = $bars->count();
            $pitch = 120 / $n;
            $barW = max(2.0, $pitch * 0.6);
            $offset = (120 - ($pitch * $n)) / 2;
            $peak = $bars->max('total');
        @endphp
        <svg viewBox="0 0 120 36" preserveAspectRatio="none" class="w-full h-9 rounded bg-slate-950/60 border border-slate-800" role="img"
             aria-label="Weekly new error events from review snapshots: {{ $bars->implode('total', ', ') }}">
            @foreach($bars as $i => $bar)
                @php
                    $h = $bar['total'] > 0 ? max(1.5, ($bar['total'] / $max) * 32) : 0.6;
                    $x = $offset + ($pitch * $i);
                    $y = 36 - $h;
                    $isPeak = $bar['total'] > 0 && $bar['total'] === $peak;
                    $isLast = $i === $n - 1;
                    $tip = sprintf(
                        '%s – %s · %d error event%s · %d incident%s%s%s',
                        $bar['label'], $bar['end'],
                        $bar['total'], $bar['total'] === 1 ? '' : 's',
                        $bar['incidents'], $bar['incidents'] === 1 ? '' : 's',
                        $bar['mttr'] !== null ? sprintf(' · MTTR %.1f h', $bar['mttr'] / 60) : '',
                        $bar['trigger'] !== '' ? ' · '.$bar['trigger'] : '',
                    );
                @endphp
                <rect x="{{ round($x, 2) }}" y="{{ round($y, 2) }}" width="{{ round($barW, 2) }}" height="{{ round($h, 2) }}"
                      class="{{ $isPeak ? 'fill-amber-400/90' : ($isLast ? 'fill-emerald-400/80' : 'fill-slate-500/70') }}">
                    <title>{{ $tip }}</title>
                </rect>
            @endforeach
        </svg>
        <div class="flex items-center justify-between mt-1">
            <span class="text-[9px] text-slate-600">{{ $bars->first()['label'] }}</span>
            <span class="text-[9px] text-slate-600">each bar = one week's new errors (hover for detail)</span>
            <span class="text-[9px] text-slate-600">{{ $bars->last()['label'] }}</span>
        </div>
    @else
        <p class="text-[11px] text-slate-500 mt-1">
            @if($bars->count() === 1)
                One week recorded so far — the trend appears from the second Monday the review is sent.
            @else
                No weekly snapshots recorded yet — they accumulate automatically each Monday the review is sent (and on manual sends).
            @endif
        </p>
    @endif
</div>
