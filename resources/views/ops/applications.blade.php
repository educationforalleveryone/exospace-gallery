@extends('ops.layout')

@section('title', 'Applications')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-xl font-semibold">Applications</h1>
        <p class="text-xs text-slate-400 mt-1">Everything the control plane knows about — synced from Coolify every 5 minutes, plus self-reporting apps.</p>
    </div>
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
                <th class="text-left px-4 py-3">Active Errors</th>
                <th class="text-left px-4 py-3">Last Sync</th>
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
                    <td class="px-4 py-3">
                        @if($app->events_count > 0)
                            <a href="{{ route('ops.events', ['application' => $app->id]) }}" class="text-orange-300 hover:text-orange-200 font-semibold">{{ $app->events_count }}</a>
                        @else
                            <span class="text-slate-600">0</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-500 text-xs">{{ $app->status_checked_at?->diffForHumans() ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-slate-500 text-sm">
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
</div>
@endsection
