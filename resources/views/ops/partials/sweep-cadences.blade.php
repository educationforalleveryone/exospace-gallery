{{-- OpsCenter — sweep cadence panel (Iteration 7). ─────────────────────────
     The measurement half of "tune OPS_SWEEP_CADENCES from real data":
     per swept check — the configured cadence, when it was ACTUALLY
     probed last (cadence skips never refresh the stamp), and whether
     an open finding forces every-sweep probing. Watch the "last
     probed" column for a few days, then set cadences to match reality.
     $sweepStatus comes from OpsSweepStatusService::status() (fail-soft,
     never throws). ───────────────────────────────────────────────────────--}}
@if($sweepStatus !== null && (count($sweepStatus['checks']) > 0 || count($sweepStatus['ignored']) > 0))
<section class="mb-10">
    <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-1">Sweep cadences — what the watch actually does</h2>
    <p class="text-xs text-slate-500 mb-3">
        The autonomous sweep runs every {{ $sweepStatus['interval_minutes'] }} minutes. Cadences throttle probing
        <em>while a check is healthy</em>; a check with an open finding is probed every sweep regardless, so recovery
        is never delayed. Measure "last probed" here over a few days before tuning
        <span class="font-mono text-xs">OPS_SWEEP_CADENCES</span>.
        @unless($sweepStatus['enabled'])
            <span class="text-amber-300 font-semibold">The sweep is currently DISABLED (OPS_SWEEP_ENABLED=false).</span>
        @endunless
    </p>
    <div class="overflow-x-auto rounded-lg border border-slate-800">
        <table class="w-full text-sm">
            <thead class="bg-slate-900/80 text-slate-400 text-xs uppercase tracking-wider">
                <tr>
                    <th class="text-left px-4 py-3">Check</th>
                    <th class="text-left px-4 py-3">Cadence</th>
                    <th class="text-left px-4 py-3">Last probed</th>
                    <th class="text-left px-4 py-3">Open finding</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/80">
                @foreach($sweepStatus['checks'] as $check)
                    <tr class="hover:bg-slate-900/60">
                        <td class="px-4 py-3">
                            <span class="text-slate-200 font-medium">{{ $check['label'] }}</span>
                            <span class="text-slate-600 font-mono text-xs ml-2">{{ $check['id'] }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs px-2 py-1 rounded border {{ $check['cadence_minutes'] !== null ? 'bg-sky-950/50 text-sky-300 border-sky-800/50' : 'bg-slate-800/60 text-slate-400 border-slate-600/50' }} font-semibold">
                                {{ $check['cadence_label'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-300 text-xs">
                            @if($check['last_probe_minutes'] !== null)
                                {{ $check['last_probe_minutes'] }} min ago
                                <span class="text-slate-600">({{ $check['last_probe_at']->format('H:i') }})</span>
                            @else
                                <span class="text-slate-500">never probed (or cache flushed)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($check['has_open_event'])
                                <span class="text-xs font-bold px-2 py-1 rounded border bg-red-950/60 text-red-300 border-red-700/50">OPEN — probed every sweep</span>
                            @else
                                <span class="text-xs px-2 py-1 rounded border bg-emerald-950/60 text-emerald-300 border-emerald-700/50 font-semibold">none</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @foreach($sweepStatus['ignored'] as $ignored)
                    <tr class="hover:bg-slate-900/60 bg-amber-950/20">
                        <td class="px-4 py-3">
                            <span class="text-amber-300 font-medium">{{ $ignored['id'] }}</span>
                        </td>
                        <td colspan="3" class="px-4 py-3 text-amber-300/80 text-xs">{{ $ignored['reason'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@endif
