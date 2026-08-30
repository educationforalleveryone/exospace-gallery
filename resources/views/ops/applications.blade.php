@extends('ops.layout')

@section('title', 'Applications')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-xl font-semibold">Applications</h1>
        <p class="text-xs text-slate-400 mt-1">Everything the control plane knows about — synced from Coolify every 5 minutes, plus self-reporting apps. Each row carries its own sub-score (same verdict-cap philosophy as the platform score — §16.2 of the master manual).</p>
    </div>
    @if(auth()->user()?->is_super_admin)
        <form method="POST" action="{{ route('ops.actions.execute', 'platform.sync') }}"
              data-busy data-busy-label="Syncing…">
            @csrf
            <button class="btn btn-sm btn-ops-secondary" title="Run the Coolify sync immediately (read-only refresh, same as the 5-minute schedule)">↻ Sync now</button>
        </form>
    @endif
</div>

<div class="overflow-x-auto rounded-lg border border-slate-800">
    <table class="w-full text-sm">
        <thead class="bg-slate-900/80 text-slate-400 text-xs uppercase tracking-wider">
            <tr>
                <th class="text-left px-4 py-3">Application</th>
                <th class="text-left px-4 py-3">Kind</th>
                <th class="text-left px-4 py-3">Environment</th>
                <th class="text-left px-4 py-3">Status</th>
                <th class="text-left px-4 py-3">Health</th>
                <th class="text-left px-4 py-3">Score</th>
                <th class="text-left px-4 py-3">Active Errors</th>
                <th class="text-left px-4 py-3">Sentry (24 h)</th>
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
                                    @if($app->is_self)<span class="text-xs px-2 py-0.5 rounded bg-cyan-900/50 text-cyan-300 border border-cyan-700/40 font-semibold">SELF</span>@endif
                                </div>
                                <div class="text-xs text-slate-500 font-mono truncate max-w-[180px]" title="{{ $app->slug }}">{{ $app->slug }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3"><span class="text-xs px-2 py-1 rounded bg-slate-800 text-slate-400 uppercase font-semibold">{{ $app->kind }}</span></td>
                    <td class="px-4 py-3 text-slate-400">{{ $app->environment }}</td>
                    <td class="px-4 py-3 text-slate-300 font-mono text-xs">{{ $app->status }}</td>
                    <td class="px-4 py-3">
                        <x-status-badge :state="$app->health" :label="$app->healthLabel()" />
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
                            <span class="text-xs font-bold px-2 py-1 rounded border {{ $scoreStyles[$appScore['band']] }}" title="{{ $scoreTooltip ?? '' }}">{{ $appScore['score'] }}</span>
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
                    <td class="px-4 py-3">
                        {{-- Iteration 8: the per-app Sentry trend cell. Mapped
                             apps get a compact sparkline; unmapped/apps with
                             the token unset get an honest muted state — the
                             mapping panel below is where the operator wires
                             them up (super-admin). --}}
                        @include('ops.partials.app-sentry-trend', [
                            'trend' => $sentryTrends[$app->id] ?? [],
                            'mapped' => filled($app->sentry_project_slug),
                        ])
                    </td>
                    <td class="px-4 py-3 text-slate-500 text-xs">{{ $app->status_checked_at?->diffForHumans() ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1.5">
                            {{-- Iteration 6: operators can run the read-only checks; restart/sync stay super-admin-only. --}}
                            @if(\App\Ops\Support\OpsAccessContext::canRunDiagnostics(auth()->user()))
                                <a href="{{ route('ops.diagnostics.index', ['app' => $app->id]) }}" class="btn btn-sm btn-ops-emerald-ghost" title="Run read-only diagnostics for this application">Run checks</a>
                                @if(auth()->user()?->is_super_admin && config('ops.actions.enabled', true) && ($app->provider === 'coolify' || $app->is_self))
                                    <a href="{{ route('ops.actions.confirm', ['action' => 'app.restart', 'app' => $app->id]) }}" class="btn btn-sm btn-ops-amber-ghost" title="Restart this application's container (confirmation required)">Restart…</a>
                                @endif
                            @else
                                <span class="btn btn-sm btn-ops-muted">view-only</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="px-4 py-10 text-center text-slate-500 text-sm">
                        Nothing synced yet. The scheduled <span class="font-mono">ops:sync-platform</span> command (every 5 minutes)
                        will discover every application, database and service on the Coolify server — provided
                        <span class="font-mono">COOLIFY_API_TOKEN</span> is configured.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4 text-xs text-slate-600 space-y-1">
    <p>Rows appear automatically from the Coolify API (provider <span class="font-mono text-slate-500">coolify</span>) and from the ingestion API (provider <span class="font-mono text-slate-500">ingest</span>).</p>
    <p>Status strings are Coolify's raw values; Health is the derived rollup (running / degraded / stopped / unknown).</p>
    <p>The per-row Score is the app-scoped sub-score: health 50% · the app's untriaged errors 30% · the app's active incidents 20%, capped at 65 when the app is stopped and 85 when degraded/untriaged-critical/active-incident — hover a badge for the full breakdown (§16.2).</p>
    <p>The Sentry column shows each application's own 24 h error trend once it is mapped to a Sentry project below (cache-first, 10-minute refresh; “API error” means the mapped project's stats call failed — hover for the reason).</p>
</div>

{{-- ── Sentry issue headlines (Iteration 9) ─────────────────────────────
     The follow-through for the trend column: one card per MAPPED app
     with its top unresolved issues by frequency + permalinks. Read-only
     data → visible to every /ops tier (viewers included); the mapping
     panel below stays super-admin-only because it is the write path.
     Fails soft per app: a broken project slug dims exactly its own
     card, never the section. --}}

@php
    $mappedApps = $applications->filter(fn ($a) => trim((string) $a->sentry_project_slug) !== '')->values();
@endphp

@if($mappedApps->isNotEmpty())
    <div class="mt-6">
        <div class="flex items-baseline justify-between mb-2">
            <h2 class="text-sm font-semibold text-slate-200">Sentry issue headlines (mapped apps, 24 h)</h2>
            @if(! $sentryConfigured)
                <span class="text-xs text-amber-400/90">API token not configured — set SENTRY_API_TOKEN to light these up</span>
            @endif
        </div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach($mappedApps as $application)
                @include('ops.partials.app-sentry-issues', [
                    'application' => $application,
                    'summary' => $sentryIssues[$application->id] ?? ['configured' => false],
                ])
            @endforeach
        </div>
        <p class="text-xs text-slate-600 mt-2">Frequency-sorted, cache-first (10-minute refresh). Click an issue to open it in Sentry — OpsCenter summarizes and links out, it never clones the stack traces.</p>
    </div>
@endif

{{-- ── Sentry project mapping (Iteration 8) ─────────────────────────────
     The AD-9 prerequisite made operator-owned: which Coolify app is
     which Sentry project. Super-admin-only write (route-enforced); the
     rest of the page is read-only for viewers/operators, so the panel
     itself is hidden from them rather than just disabled. --}}
@if(auth()->user()?->is_super_admin)
    <div class="mt-6 rounded-lg border border-slate-800 bg-slate-900/40">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
            <div>
                <h2 class="text-sm font-semibold text-slate-200">Sentry project mapping</h2>
                <p class="text-xs text-slate-500 mt-0.5">Which Sentry project belongs to which application — the prerequisite for the per-app trend column. Slugs live in Sentry URLs (sentry.io/organizations/<span class="font-mono">{{ config('ops.sentry.org') ?: 'your-org' }}</span>/projects/<span class="font-mono">&lt;slug&gt;</span>). Empty = unmapped.</p>
            </div>
            @if(! $sentryConfigured)
                <span class="text-xs px-2 py-1 rounded border border-amber-700/50 bg-amber-950/50 text-amber-300 font-semibold shrink-0" title="SENTRY_API_TOKEN / SENTRY_ORG_SLUG are not set — mappings can be saved but no trend will render until they are">API token not configured</span>
            @endif
        </div>
        <div class="divide-y divide-slate-800/60">
            @forelse($applications as $app)
                <form method="POST" action="{{ route('ops.applications.sentry', $app) }}" class="px-4 py-2.5 flex items-center gap-3">
                    @csrf
                    <div class="w-56 min-w-0">
                        <div class="text-xs text-slate-200 truncate font-medium">{{ $app->name }}</div>
                        <div class="text-xs text-slate-500 font-mono truncate">{{ $app->slug }}</div>
                    </div>
                    <input type="text" name="sentry_project_slug" value="{{ $app->sentry_project_slug }}"
                           placeholder="not mapped"
                           maxlength="100"
                           class="input-ops-sm flex-1 max-w-xs bg-slate-950/70 font-mono focus:border-cyan-600"
                           title="Lowercase letters, digits, dashes, dots, underscores">
                    <button type="submit" class="btn btn-sm btn-ops-secondary shrink-0">Save</button>
                </form>
            @empty
                <p class="px-4 py-6 text-center text-slate-500 text-xs">No applications yet — the panel populates once the platform sync runs.</p>
            @endforelse
        </div>
        <p class="px-4 py-2.5 border-t border-slate-800 text-xs text-slate-600">Every save is audited (<span class="font-mono">ops.sentry.mapping</span>) with the old → new slug. The token itself stays in env — a slug is a public label, not a secret.</p>
    </div>
@endif
@endsection
