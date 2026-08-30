@extends('ops.layout')

@section('title', 'Overview')

@php
    $statusStyles = [
        'healthy'  => ['chip' => 'bg-emerald-950/60 text-emerald-300 border-emerald-700/50',   'dot' => 'bg-emerald-400', 'label' => 'HEALTHY'],
        'degraded' => ['chip' => 'bg-amber-950/60 text-amber-300 border-amber-700/50',         'dot' => 'bg-amber-400',   'label' => 'DEGRADED'],
        'critical' => ['chip' => 'bg-red-950/60 text-red-300 border-red-700/50',               'dot' => 'bg-red-400',     'label' => 'CRITICAL'],
        'unknown'  => ['chip' => 'bg-slate-800/60 text-slate-300 border-slate-600/50',         'dot' => 'bg-slate-400',   'label' => 'UNKNOWN'],
    ];
@endphp

@section('content')

{{-- ── Platform status hero ──────────────────────────────────────────── --}}
@php
    $hero = $statusStyles[$platform['status']] ?? $statusStyles['unknown'];
    $scoreStyles = [
        'healthy'  => 'bg-emerald-950/60 text-emerald-300 border-emerald-700/50',
        'degraded' => 'bg-amber-950/60 text-amber-300 border-amber-700/50',
        'critical' => 'bg-red-950/60 text-red-300 border-red-700/50',
    ];
    $scoreStyle = $scoreStyles[$healthScore['band']] ?? $scoreStyles['critical'];
@endphp
<section class="rounded-xl border {{ str_replace(['bg-emerald-950/60','bg-amber-950/60','bg-red-950/60','bg-slate-800/60'], '', $hero['chip']) }} border-slate-800 bg-slate-900/60 p-5 mb-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <span class="relative flex h-3.5 w-3.5">
                <span class="absolute inline-flex h-full w-full rounded-full {{ $hero['dot'] }} opacity-60 animate-ping"></span>
                <span class="relative inline-flex rounded-full h-3.5 w-3.5 {{ $hero['dot'] }}"></span>
            </span>
            <div>
                <h1 class="text-lg font-semibold tracking-wide flex items-center flex-wrap gap-3">
                    <span>PLATFORM STATUS: <span class="text-2xl align-middle ml-1">{{ $hero['label'] }}</span></span>
                    {{-- Iteration 4: the quantified verdict — same band as the status label, with the breakdown card right below --}}
                    <span class="inline-flex items-baseline gap-1.5 px-3 py-1 rounded-lg border {{ $scoreStyle }} text-sm font-bold align-middle" title="Weighted health score — see the breakdown below">
                        <span class="text-xl">{{ $healthScore['score'] }}</span><span class="text-xs font-normal opacity-70">/100</span>
                    </span>
                </h1>
                <p class="text-xs text-slate-400 mt-0.5">
                    Last platform sync: {{ $lastSync?->diffForHumans() ?? 'pending (runs every 5 minutes)' }}
                </p>
            </div>
        </div>
        <div class="flex gap-3 text-center">
            <div class="rounded-lg bg-slate-950/60 border border-slate-800 px-4 py-2">
                <div class="text-xl font-bold {{ ($openCounts['critical'] ?? 0) > 0 ? 'text-red-400' : 'text-slate-400' }}">{{ $openCounts['critical'] ?? 0 }}</div>
                <div class="text-xs uppercase tracking-wider text-slate-500">Critical</div>
            </div>
            <div class="rounded-lg bg-slate-950/60 border border-slate-800 px-4 py-2">
                <div class="text-xl font-bold {{ ($openCounts['error'] ?? 0) > 0 ? 'text-orange-400' : 'text-slate-400' }}">{{ $openCounts['error'] ?? 0 }}</div>
                <div class="text-xs uppercase tracking-wider text-slate-500">Errors</div>
            </div>
            <div class="rounded-lg bg-slate-950/60 border border-slate-800 px-4 py-2">
                <div class="text-xl font-bold {{ ($openCounts['warning'] ?? 0) > 0 ? 'text-amber-400' : 'text-slate-400' }}">{{ $openCounts['warning'] ?? 0 }}</div>
                <div class="text-xs uppercase tracking-wider text-slate-500">Warnings</div>
            </div>
        </div>
    </div>
    <ul class="mt-4 space-y-1.5">
        @foreach($platform['reasons'] as $reason)
            <li class="text-sm text-slate-300 flex gap-2"><span class="text-slate-600">—</span>{{ $reason }}</li>
        @endforeach
    </ul>
</section>

{{-- ── Health score breakdown (Iteration 4) ─────────────────────────── --}}
@include('ops.partials.score-breakdown', ['healthScore' => $healthScore])

{{-- ── Active incidents (Iteration 2) ─────────────────────────────────────── --}}
@if($activeIncidents->isNotEmpty())
<section class="mb-6">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-red-400">Active Incidents — Correlated Stories</h2>
        <a href="{{ route('ops.incidents.index') }}" class="text-xs text-emerald-400 hover:text-emerald-300">All incidents →</a>
    </div>
    <div class="grid md:grid-cols-2 gap-3">
        @foreach($activeIncidents as $incident)
            @php
                $sevStyle = [
                    'critical' => 'border-red-800/60 bg-red-950/40',
                    'error'    => 'border-orange-800/50 bg-orange-950/30',
                    'warning'  => 'border-amber-800/50 bg-amber-950/30',
                    'info'     => 'border-slate-700/50 bg-slate-900/40',
                ][$incident->severity] ?? 'border-slate-700/50 bg-slate-900/40';
            @endphp
            <a href="{{ route('ops.incidents.show', $incident) }}" class="block rounded-lg border {{ $sevStyle }} p-4 hover:brightness-125 transition">
                <div class="flex items-center justify-between gap-2">
                    <x-status-badge :state="$incident->severity === 'error' ? 'critical' : $incident->severity" :label="strtoupper($incident->severity).'. #'.$incident->id" />
                    <span class="text-xs text-slate-500">{{ $incident->event_count }} events · {{ $incident->confidence }} confidence</span>
                </div>
                <div class="text-sm font-medium text-slate-100 mt-1.5">{{ $incident->title }}</div>
                <div class="text-xs text-slate-400 mt-1">{{ $incident->rootCauseStatement() }}</div>
            </a>
        @endforeach
    </div>
</section>
@endif

<div class="grid lg:grid-cols-3 gap-6">

    {{-- ── Application health grid ─────────────────────────────────── --}}
    <section class="lg:col-span-2 space-y-6">
        <div>
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Application Health</h2>
                <a href="{{ route('ops.applications') }}" class="text-xs text-emerald-400 hover:text-emerald-300">View all →</a>
            </div>

            @if($applications->isEmpty())
                <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-6 text-sm text-slate-400 text-center">
                    No applications yet. The first <span class="font-mono">ops:sync-platform</span> run (every 5 minutes) will
                    populate every application, database and service on the Coolify server.
                </div>
            @else
                <div class="grid sm:grid-cols-2 gap-3">
                    @foreach($applications as $entry)
                        @php
                            $app = $entry['application'];
                            $style = $statusStyles[$entry['status']] ?? $statusStyles['unknown'];
                        @endphp
                        <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="font-medium text-slate-100 flex items-center gap-2">
                                        {{ $app->name }}
                                        @if($app->is_self)<span class="text-xs px-1.5 py-0.5 rounded bg-cyan-900/50 text-cyan-300 border border-cyan-700/40 font-semibold">SELF</span>@endif
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 border border-slate-700/50 uppercase">{{ $app->kind }}</span>
                                    </div>
                                    <div class="text-xs text-slate-500 mt-0.5 font-mono truncate max-w-[220px]">{{ $app->url ?? $app->slug }}</div>
                                </div>
                                <span class="status {{ $style['chip'] }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $style['dot'] }}"></span>{{ $style['label'] }}
                                </span>
                            </div>
                            <p class="text-xs text-slate-400 mt-2">{{ $entry['reasons'][0] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── Recent errors ───────────────────────────────────────── --}}
        <div>
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Active Errors — What Needs Attention</h2>
                <a href="{{ route('ops.events') }}" class="text-xs text-emerald-400 hover:text-emerald-300">All events →</a>
            </div>

            @if($recentEvents->isEmpty())
                <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-6 text-sm text-slate-400 text-center">
                    No active error-level events. Quiet is good.
                </div>
            @else
                <div class="rounded-lg border border-slate-800 divide-y divide-slate-800/80 overflow-hidden">
                    @foreach($recentEvents as $event)
                        @php
                            $sev = [
                                'critical' => ['bg-red-950/50','text-red-300','border-red-800/60','CRITICAL'],
                                'error'    => ['bg-orange-950/40','text-orange-300','border-orange-800/50','ERROR'],
                                'warning'  => ['bg-amber-950/40','text-amber-300','border-amber-800/50','WARNING'],
                                'info'     => ['bg-slate-900/40','text-slate-300','border-slate-700/50','INFO'],
                            ][$event->severity] ?? ['bg-slate-900/40','text-slate-300','border-slate-700/50','INFO'];
                        @endphp
                        <a href="{{ route('ops.events.show', $event) }}" class="block bg-slate-900/40 hover:bg-slate-900/80 transition p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-xs font-bold px-1.5 py-0.5 rounded border {{ $sev[2] }} {{ $sev[0] }} {{ $sev[1] }}">{{ $sev[3] }}</span>
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 font-mono">{{ $event->category }}</span>
                                        <span class="text-sm font-medium text-slate-100 truncate">{{ $event->title }}</span>
                                    </div>
                                    <div class="text-xs text-slate-500 mt-1">
                                        {{ $event->application?->name ?? 'Unattributed' }} · last seen {{ $event->last_seen_at?->diffForHumans() }} · {{ $event->occurrence_count }}× occurrence{{ $event->occurrence_count === 1 ? '' : 's' }}
                                    </div>
                                </div>
                                <span class="text-slate-600 text-sm shrink-0">→</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- ── Right column ──────────────────────────────────────────────── --}}
    <section class="space-y-6">
        <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400 mb-3">{{ $selfApp?->name ?? 'Host Application' }} — Subsystems</h2>
            @php $selfStyle = $statusStyles[$selfChecks['status']] ?? $statusStyles['unknown']; @endphp
            <div class="status {{ $selfStyle['chip'] }} mb-3">
                <span class="w-1.5 h-1.5 rounded-full {{ $selfStyle['dot'] }}"></span>{{ $selfStyle['label'] }}
            </div>
            <ul class="space-y-1.5">
                @forelse($selfChecks['reasons'] as $reason)
                    <li class="text-xs text-slate-300 flex gap-2"><span class="text-slate-600">—</span>{{ $reason }}</li>
                @empty
                    <li class="text-xs text-emerald-400">All subsystem checks passed (DB, cache, queue, scheduler, backups, disk)</li>
                @endforelse
            </ul>
            <p class="text-xs text-slate-600 mt-3">Checks mirror /health, JobHeartbeatService, backup freshness and disk thresholds — read-only, no new monitors.</p>
        </div>

        <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400 mb-3">Deployments (24h)</h2>
            @if($recentDeployments->isEmpty())
                <p class="text-xs text-slate-500">No deployments or build events recorded in the last 24 hours.</p>
            @else
                <ul class="space-y-2.5">
                    @foreach($recentDeployments as $deployment)
                        <li>
                            <a href="{{ route('ops.events.show', $deployment) }}" class="text-xs text-slate-200 hover:text-emerald-300">
                                <span class="font-mono px-1.5 py-0.5 rounded text-xs {{ $deployment->severity === 'critical' ? 'bg-red-950/60 text-red-300' : 'bg-emerald-950/60 text-emerald-300' }}">{{ strtoupper($deployment->category) }}</span>
                                {{ $deployment->title }}
                            </a>
                            <div class="text-xs text-slate-600 mt-0.5">
                                {{ $deployment->application?->name }} · {{ $deployment->last_seen_at?->diffForHumans() }}
                                @if(data_get($deployment->context, 'commit')) · commit {{ \Illuminate\Support\Str::limit(data_get($deployment->context, 'commit'), 8, '') }}@endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- ── Backup / Webhook / Sentry tiles (Iteration 4) ───────────── --}}
        @include('ops.partials.overview-tiles', [
            'backupTile' => $backupTile,
            'webhookTile' => $webhookTile,
            'sentryTile' => $sentryTile,
        ])

        <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400 mb-2">Shipped</h2>
            <ul class="text-xs text-slate-500 space-y-1.5">
                <li><span class="text-slate-400 font-medium">Iter 2:</span> incidents, correlation engine, timelines — related errors are one story.</li>
                <li><span class="text-slate-400 font-medium">Iter 3:</span> one-click read-only diagnostics + safe actions (restart, replay).</li>
                <li><span class="text-slate-400 font-medium">Iter 4:</span> health score, Sentry summary, backup/webhook tiles, and the 15-minute autonomous sweep — problems now find YOU (deduplicated events + Slack), no dashboard visit required.</li>
                <li><span class="text-slate-400 font-medium">Iter 5:</span> viewer access without super-admin blast radius, the credential-rotation ledger (§15 made live), and per-application sub-scores.</li>
            </ul>
        </div>
    </section>
</div>
@endsection
