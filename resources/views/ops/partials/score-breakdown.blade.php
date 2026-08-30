{{-- OpsCenter (Iteration 4): platform health score breakdown — the formula
     in human form. The score is never shown without its components and
     reasons (brief rule: no meaningless numbers). Formula documented in
     docs/MASTER_MANUAL_OPERATIONS.md §16 and in OpsHealthScoreService. --}}

@php
    $bandStyles = [
        'healthy'  => ['text' => 'text-emerald-300', 'bar' => 'bg-emerald-400'],
        'degraded' => ['text' => 'text-amber-300',   'bar' => 'bg-amber-400'],
        'critical' => ['text' => 'text-red-300',     'bar' => 'bg-red-400'],
    ];
    $band = $bandStyles[$healthScore['band']] ?? $bandStyles['critical'];
@endphp

<section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5 mb-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Health Score — Why the Number</h2>
            <p class="text-xs text-slate-600 mt-0.5">
                Weighted sum of five components (formula: master manual §16). 90–100 healthy · 70–89 degraded · below 70 critical.
            </p>
        </div>
        <div class="text-right">
            <div class="text-2xl font-semibold text-numeric {{ $band['text'] }}">{{ $healthScore['score'] }}<span class="text-base text-slate-500 font-normal">/100</span></div>
            <div class="text-xs uppercase tracking-wider text-slate-500">{{ strtoupper($healthScore['band']) }}</div>
        </div>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-3">
        @foreach($healthScore['components'] as $key => $component)
            <div class="rounded-lg border border-slate-800 bg-slate-950/50 p-3">
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-xs font-medium text-slate-200">{{ $component['name'] }}</span>
                    <span class="text-xs text-slate-500">{{ $component['weight'] }}%</span>
                </div>
                <div class="mt-2 h-1.5 rounded-full bg-slate-800 overflow-hidden">
                    <div class="h-full rounded-full {{ $component['score'] >= 90 ? 'bg-emerald-400' : ($component['score'] >= 70 ? 'bg-amber-400' : 'bg-red-400') }}"
                         style="width: {{ max(2, min(100, $component['score'])) }}%"></div>
                </div>
                <div class="mt-1.5 text-xs {{ $component['score'] >= 90 ? 'text-emerald-300' : ($component['score'] >= 70 ? 'text-amber-300' : 'text-red-300') }}">
                    {{ $component['score'] }}/100
                </div>
                <ul class="mt-2 space-y-1">
                    @foreach($component['reasons'] as $reason)
                        <li class="text-xs text-slate-400 leading-snug flex gap-1"><span class="text-slate-700">·</span>{{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>

    {{-- Verdict caps: the anti-rose-colored-glasses rules currently in force.
         Empty on a clean platform; never empty when the label is worse than
         the blend would suggest. --}}
    @if(! empty($healthScore['applied_caps']))
        <div class="mt-4 rounded-lg border border-amber-800/50 bg-amber-950/30 px-4 py-3">
            <div class="text-xs font-semibold uppercase tracking-wider text-amber-300 mb-1.5">Verdict caps applied</div>
            <ul class="space-y-1">
                @foreach($healthScore['applied_caps'] as $cap)
                    <li class="text-xs text-amber-200/90 flex gap-2"><span class="text-amber-600">▸</span>{{ $cap }}</li>
                @endforeach
            </ul>
            <p class="text-xs text-slate-500 mt-2">
                The blend can never read rosier than the status verdict — a localized outage (label CRITICAL, score high-ish) means ONE serious problem; a low score with a DEGRADED label means many small ones.
            </p>
        </div>
    @endif
</section>
