@extends('ops.layout')

@section('title', 'Applications')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-xl font-semibold">Applications</h1>
        <p class="text-xs text-slate-400 mt-1">Everything the control plane knows about — synced from Coolify every 5 minutes, plus self-reporting apps. Each row carries its own sub-score (same verdict-cap philosophy as the platform score — §16.2 of the master manual).</p>
    </div>
    @if(auth()->user()?->is_super_admin)
        <form method="POST" action="{{ route('ops.actions.execute', 'platform.sync') }}">
            @csrf
            <button class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-medium text-slate-200 transition" title="Run the Coolify sync immediately (read-only refresh, same as the 5-minute schedule)">↻ Sync now</button>
        </form>
    @endif
</div>

<div class="overflow-x-auto rounded-lg border border-slate-800">
    <table class="w-full text-sm">
        <thead class="bg-slate-900/80 text-slate-400 text-[11px] uppercase tracking-wider">
            <tr>
                <th class="text-left px-4 py-3">Application</th>
                <th class="text-left px-4 py-3">Kind</th>
                <th class="text-left px-4 py-3">Environment</th>
                <th class="text-left px-4 py-3">Status</th>
                <th class="text-left px-4 py-3">Health</th>
                <th class="text-left px-4 py-3">Score</th>
                <th class="text-left px-4 py-3">Active Errors</th>
                <th class="text-left px-4 py-3">Last Sync</th>
                <th class="text-left px-4 py-3">Diagnostics</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/80">
            @forelse($applications as $app)
                @php
                    $healthStyles = [
                        'running'  => 'bg-emerald-950/60 text-emerald-300 border-emerald-700/50',
                        'degraded' => 'bg-amber-950/60 text-amber-300 border-amber-700/50',
                        'stopped'  => 'bg-red-950/60 text-red-300 border-red-700/50',
                        'unknown'  => 'bg-slate-800/60 text-slate-400 border-slate-600/50',
                    ];
                @endphp
                <tr class="hover:bg-slate-900/60">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div>
                                <div class="font-medium text-slate-100 flex items-center gap-2">
                                    {{ $app->name }}
                                    @if($app->is_self)<span class="text-[9px] px-1.5 py-0.5 rounded bg-cyan-900/50 text-cyan-300 border border-cyan-700/40 font-semibold">SELF</span>@endif
                                </div>
                                <div class="text-[11px] text-slate-500 font-mono">{{ $app->slug }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3"><span class="text-[10px] px-2 py-1 rounded bg-slate-800 text-slate-400 uppercase font-semibold">{{ $app->kind }}</span></td>
                    <td class="px-4 py-3 text-slate-400">{{ $app->environment }}</td>
                    <td class="px-4 py-3 text-slate-300 font-mono text-xs">{{ $app->status }}</td>
                    <td class="px-4 py-3">
                        <span class="text-[10px] font-bold px-2 py-1 rounded border {{ $healthStyles[$app->health] ?? $healthStyles['unknown'] }}">{{ $app->healthLabel() }}</span>
                    </td>
                    @php
                        $appScore = $scores[$app->id] ?? null;
                        $scoreStyles = [
                            'healthy'  => 'bg-emerald-950/60 text-emerald-300 border-emerald-700/50',
                            'degraded' => 'bg-amber-950/60 text-amber-300 border-amber-700/50',
                            'critical' => 'bg-red-950/60 text-red-300 border-red-700/50',
                        ];
                        if ($appScore) {
                            $scoreTooltip = 'Sub-score '.$appScore['score'].'/100 — '.$appScore['band'].PHP_EOL;
                            foreach ($appScore['components'] as $component) {
                                $scoreTooltip .= $component['name'].': '.$component['score'].'/100 (weight '.$component['weight'].'%) — '.implode('; ', $component['reasons']).PHP_EOL;
                            }
                            if ($appScore['applied_caps'] !== []) {
                                $scoreTooltip .= 'Caps applied: '.implode(' | ', $appScore['applied_caps']);
                            }
                        }
                    @endphp
                    <td class="px-4 py-3">
                        @if($appScore)
                            <span class="text-[11px] font-bold px-2 py-1 rounded border {{ $scoreStyles[$appScore['band']] }}" title="{{ $scoreTooltip ?? '' }}">{{ $appScore['score'] }}</span>
                        @else
                            <span class="text-slate-600 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($app->events_count > 0)
                            <a href="{{ route('ops.events', ['application' => $app->id]) }}" class="text-orange-300 hover:text-orange-200 font-semibold">{{ $app->events_count }}</a>
                        @else
                            <span class="text-slate-600">0</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-500 text-xs">{{ $app->status_checked_at?->diffForHumans() ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1.5">
                            {{-- Iteration 6: operators can run the read-only checks; restart/sync stay super-admin-only. --}}
                            @if(\App\Ops\Support\OpsAccessContext::canRunDiagnostics(auth()->user()))
                                <a href="{{ route('ops.diagnostics.index', ['app' => $app->id]) }}" class="text-[11px] px-2 py-1 rounded border border-emerald-700/60 bg-emerald-950/40 text-emerald-300 hover:bg-emerald-900/60" title="Run read-only diagnostics for this application">Run checks</a>
                                @if(auth()->user()?->is_super_admin && config('ops.actions.enabled', true) && ($app->provider === 'coolify' || $app->is_self))
                                    <a href="{{ route('ops.actions.confirm', ['action' => 'app.restart', 'app' => $app->id]) }}" class="text-[11px] px-2 py-1 rounded border border-amber-700/60 bg-amber-950/40 text-amber-300 hover:bg-amber-900/60" title="Restart this application's container (confirmation required)">Restart…</a>
                                @endif
                            @else
                                <span class="text-[11px] text-slate-600">view-only</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="px-4 py-10 text-center text-slate-500 text-sm">
                        Nothing synced yet. The scheduled <span class="font-mono">ops:sync-platform</span> command (every 5 minutes)
                        will discover every application, database and service on the Coolify server — provided
                        <span class="font-mono">COOLIFY_API_TOKEN</span> is configured.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4 text-[11px] text-slate-600 space-y-1">
    <p>Rows appear automatically from the Coolify API (provider <span class="font-mono text-slate-500">coolify</span>) and from the ingestion API (provider <span class="font-mono text-slate-500">ingest</span>).</p>
    <p>Status strings are Coolify's raw values; Health is the derived rollup (running / degraded / stopped / unknown).</p>
    <p>The per-row Score is the app-scoped sub-score: health 50% · the app's untriaged errors 30% · the app's active incidents 20%, capped at 65 when the app is stopped and 85 when degraded/untriaged-critical/active-incident — hover a badge for the full breakdown (§16.2).</p>
</div>
@endsection
