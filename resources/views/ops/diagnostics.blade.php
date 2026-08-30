@extends('ops.layout')

@section('title', 'Diagnostics')

@section('content')
<div class="flex flex-wrap items-start justify-between gap-4 mb-4">
    <div>
        <h1 class="text-xl font-semibold">Diagnostics</h1>
        <p class="text-xs text-slate-400 mt-1">
            One-click, read-only checks — no SSH, no Docker commands, no remembering anything.
            Every run is audited; results are explained in plain language.
        </p>
    </div>
    <div class="flex items-center gap-2">
        <form method="GET" action="{{ route('ops.diagnostics.index') }}" class="flex items-center gap-2">
            <select name="app" class="input-ops-sm w-auto" data-change="submitForm">
                <option value="">Target: Control plane host (self)</option>
                @foreach($applications as $app)
                    <option value="{{ $app->id }}" @selected($application?->id === $app->id)>Target: {{ $app->name }}</option>
                @endforeach
            </select>
            <noscript><button class="btn btn-sm btn-ops-secondary">Apply</button></noscript>
        </form>
    </div>
</div>

@if($application)
<div class="mb-5 rounded-lg border border-cyan-800/50 bg-cyan-950/30 px-4 py-3 text-sm text-cyan-200">
    Showing diagnostics for <strong>{{ $application->name }}</strong>.
    Checks that inspect the control plane's own subsystems (its database, Redis, queue) answer for the
    control plane host — they will tell you so honestly when aimed at another application.
    <a href="{{ route('ops.diagnostics.index') }}" class="text-cyan-300 hover:text-cyan-200 underline">Reset to self</a>
</div>
@endif

{{-- ── Diagnostic catalog, grouped ─────────────────────────────────────── --}}
<div class="space-y-8 mb-10">
    @foreach($groups as $group)
        @php
            $groupDiagnostics = collect($diagnostics)->where('group', $group);
        @endphp
        <section>
            <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">{{ $group }}</h2>
            <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach($groupDiagnostics as $id => $definition)
                    <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4 flex flex-col">
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <h3 class="text-sm font-semibold text-slate-100">{{ $definition['label'] }}</h3>
                            <span class="text-xs px-2 py-0.5 rounded border {{ $definition['scope'] === 'self' ? 'bg-cyan-950/50 text-cyan-300 border-cyan-800/50' : 'bg-emerald-950/50 text-emerald-300 border-emerald-800/50' }} font-semibold shrink-0">
                                {{ $definition['scope'] === 'self' ? 'SELF' : 'PER APP' }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed mb-4 flex-1">{{ $definition['description'] }}</p>
                        {{-- Iteration 6: super-admins AND operator-tier grantees can run these read-only checks (route-level: ops_operator group). --}}
                        @if(\App\Ops\Support\OpsAccessContext::canRunDiagnostics(auth()->user()))
                            <form method="POST" action="{{ route('ops.diagnostics.run') }}"
                                  data-busy data-busy-label="Running…">
                                @csrf
                                <input type="hidden" name="diagnostic" value="{{ $id }}">
                                @if($application)<input type="hidden" name="application" value="{{ $application->id }}">@endif
                                {{-- ITERATION-3: data-busy — the run is synchronous (seconds), the
                                     button used to stay clickable → double runs + double queue rows. --}}
                                <button class="btn btn-sm btn-ops-primary w-full">
                                    Run diagnostic
                                </button>
                            </form>
                        @else
                            <span class="btn btn-sm btn-ops-muted w-full">viewer (read-only)</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>

{{-- ── Sweep cadences (Iteration 7) ─────────────────────────────────────── --}}
@include('ops.partials.sweep-cadences')

{{-- ── Recent runs ────────────────────────────────────────────────────── --}}
<section>
    <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">Recent runs</h2>
    <div class="overflow-x-auto rounded-lg border border-slate-800">
        <table class="w-full text-sm">
            <thead class="bg-slate-900/80 text-slate-400 text-xs uppercase tracking-wider">
                <tr>
                    <th class="text-left px-4 py-3">Status</th>
                    <th class="text-left px-4 py-3">Diagnostic</th>
                    <th class="text-left px-4 py-3">Application</th>
                    <th class="text-left px-4 py-3">Summary</th>
                    <th class="text-left px-4 py-3">Duration</th>
                    <th class="text-left px-4 py-3">By</th>
                    <th class="text-left px-4 py-3">When</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/80">
                @forelse($recentRuns as $run)
                    @php
                        $statusStyles = [
                            'healthy'      => 'bg-emerald-950/60 text-emerald-300 border-emerald-700/50',
                            'degraded'     => 'bg-amber-950/60 text-amber-300 border-amber-700/50',
                            'failed'       => 'bg-red-950/60 text-red-300 border-red-700/50',
                            'inconclusive' => 'bg-slate-800/60 text-slate-400 border-slate-600/50',
                        ];
                    @endphp
                    <tr class="hover:bg-slate-900/60">
                        <td class="px-4 py-3"><x-status-badge :state="match($run->status) { 'healthy' => 'healthy', 'degraded' => 'warning', 'failed' => 'critical', default => 'unknown' }" :label="$run->statusLabel()" /></td>
                        <td class="px-4 py-3"><a href="{{ route('ops.diagnostics.show', $run) }}" class="text-slate-200 hover:text-emerald-300 font-medium">{{ \App\Ops\Diagnostics\DiagnosticRegistry::label($run->diagnostic_id) }}</a></td>
                        <td class="px-4 py-3 text-slate-400">{{ $run->application?->name ?? 'Control plane (self)' }}</td>
                        <td class="px-4 py-3 text-slate-300 max-w-md"><span class="line-clamp-1">{{ $run->summary }}</span></td>
                        <td class="px-4 py-3 text-slate-500 font-mono text-xs">{{ $run->duration_ms }} ms</td>
                        <td class="px-4 py-3 text-slate-500 text-xs">{{ $run->actor?->name ?? $run->actor?->email ?? 'system' }}</td>
                        <td class="px-4 py-3 text-slate-500 text-xs">{{ $run->created_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-slate-500 text-sm">
                            No diagnostics have been run yet. Run one above, or use the recommended-diagnostics
                            buttons on any error or incident page.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<div class="mt-4 text-xs text-slate-600 space-y-1">
    <p>Diagnostics are read-only by construction: the engine runs a fixed, allow-listed catalog of checks — never commands, never arbitrary SQL, never docker exec.</p>
    <p>Results are redacted, persisted for {{ config('ops.diagnostics.retention_days', 30) }} days and audited (AdminAuditLog <span class="font-mono">ops.diagnostic.run</span>).</p>
</div>
@endsection
