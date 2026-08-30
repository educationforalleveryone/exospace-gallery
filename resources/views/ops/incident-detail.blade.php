@extends('ops.layout')

@section('title', $incident->title)

@section('content')
<div class="mb-4">
    <a href="{{ route('ops.incidents.index') }}" class="text-xs text-slate-500 hover:text-slate-300">← Back to incidents</a>
</div>

@php
    $sev = [
        'critical' => ['text' => 'CRITICAL', 'chip' => 'bg-red-950/60 text-red-300 border-red-700/60'],
        'error'    => ['text' => 'ERROR',    'chip' => 'bg-orange-950/50 text-orange-300 border-orange-700/50'],
        'warning'  => ['text' => 'WARNING',  'chip' => 'bg-amber-950/50 text-amber-300 border-amber-700/50'],
        'info'     => ['text' => 'INFO',     'chip' => 'bg-slate-800/60 text-slate-300 border-slate-600/50'],
    ][$incident->severity] ?? ['text' => strtoupper($incident->severity), 'chip' => 'bg-slate-800/60 text-slate-300 border-slate-600/50'];
@endphp

{{-- ── Header + actions ───────────────────────────────────────────────── --}}
<div class="rounded-xl border {{ $sev['chip'] }} border-slate-800 p-5 mb-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1.5">
                <span class="text-xs font-bold px-2 py-1 rounded border {{ $sev['chip'] }}">{{ $sev['text'] }}</span>
                <span class="text-xs px-2 py-1 rounded bg-slate-800 text-slate-400 uppercase">incident #{{ $incident->id }}</span>
                <span class="text-xs px-2 py-1 rounded {{ $incident->status === 'open' ? 'bg-slate-800 text-slate-300' : ($incident->status === 'resolved' ? 'bg-slate-900 text-slate-500 border border-slate-800' : 'bg-emerald-950/60 text-emerald-300 border border-emerald-800/50') }}">{{ $incident->status }}</span>
            </div>
            <h1 class="text-2xl font-semibold text-slate-50">{{ $incident->title }}</h1>
            <p class="text-xs text-slate-400 mt-1.5">
                {{ $incident->application?->name ?? 'Unattributed' }} ·
                {{ $incident->event_count }} correlated event(s) ·
                started {{ $incident->first_event_at?->diffForHumans() }} ·
                last activity {{ $incident->last_event_at?->diffForHumans() }}
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if(auth()->user()?->is_super_admin)
                @if($incident->status === 'open')
                    <form method="POST" action="{{ route('ops.incidents.acknowledge', $incident) }}">
                        @csrf
                        <button class="btn btn-ops-primary">Acknowledge</button>
                    </form>
                @endif
                @if($incident->status !== 'resolved')
                    <form method="POST" action="{{ route('ops.incidents.resolve', $incident) }}">
                        @csrf
                        <button class="btn btn-ops-primary">Resolve</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('ops.incidents.reopen', $incident) }}">
                        @csrf
                        <button class="btn btn-ops-secondary">Reopen</button>
                    </form>
                @endif
            @else
                <span class="text-xs px-2 py-1.5 rounded bg-cyan-950/50 text-cyan-300 border border-cyan-800/50 font-bold self-center" title="Lifecycle actions are super-admin-only — operators and viewers can still run the recommended diagnostics below.">READ-ONLY VIEW</span>
            @endif
        </div>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-6">

    {{-- ── Main column: the timeline ──────────────────────────────────── --}}
    <div class="lg:col-span-2 space-y-6">

        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-4">Incident timeline</h2>

            @if($timeline->isEmpty())
                <p class="text-sm text-slate-400">No member events recorded.</p>
            @else
                <ol class="relative border-l border-slate-700/60 ml-3 space-y-6">
                    @foreach($timeline as $event)
                        @php
                            $dot = [
                                'critical' => 'bg-red-400',
                                'error'    => 'bg-orange-400',
                                'warning'  => 'bg-amber-400',
                                'info'     => 'bg-slate-400',
                            ][$event->severity] ?? 'bg-slate-400';
                            $isRoot = $event->id === $incident->root_cause_event_id;
                        @endphp
                        <li class="ml-6">
                            <span class="absolute -left-[7px] mt-1.5 w-3.5 h-3.5 rounded-full border-2 border-slate-950 {{ $dot }}"></span>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs text-slate-500 font-mono w-32 shrink-0">{{ $event->first_seen_at?->format('H:i') ?? '—' }}</span>
                                <span class="text-xs font-bold px-1.5 py-0.5 rounded {{ $sev['chip'] }}">{{ strtoupper($event->severity) }}</span>
                                <span class="text-xs font-mono px-1.5 py-0.5 rounded bg-slate-800 text-slate-400">{{ $event->category }}</span>
                                @if($isRoot)<span class="text-xs px-1.5 py-0.5 rounded bg-fuchsia-950/60 text-fuchsia-300 border border-fuchsia-800/50 font-bold">ROOT CAUSE CANDIDATE</span>@endif
                            </div>
                            <a href="{{ route('ops.events.show', $event) }}" class="block text-sm text-slate-200 hover:text-emerald-300 mt-1">{{ $event->title }}</a>
                            @if($event->occurrence_count > 1)
                                <span class="text-xs text-slate-500">{{ $event->occurrence_count }}× occurrences · last {{ $event->last_seen_at?->diffForHumans() }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">Root cause candidate</h2>
            <p class="text-sm text-slate-200 font-medium">{{ $incident->rootCauseStatement() }}</p>
            @if($incident->confidence === 'high')
                <p class="text-xs text-slate-400 mt-2">A causal event (deployment / build / migration) demonstrably preceded these symptoms — strong evidence for a change-triggered failure.</p>
            @elseif($incident->confidence === 'medium')
                <p class="text-xs text-slate-400 mt-2">These events cluster in time on the same application. Evidence suggests a shared cause, but no triggering change was identified.</p>
            @else
                <p class="text-xs text-slate-400 mt-2">Single-event incident — no correlated cluster. Inspect the event's own diagnostics.</p>
            @endif
            @if($incident->rootCause)
                <a href="{{ route('ops.events.show', $incident->rootCause) }}" class="inline-block mt-3 text-xs text-emerald-400 hover:text-emerald-300">Inspect root-cause event →</a>
            @endif
        </section>

        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">Why it matters</h2>
            <p class="text-sm text-slate-300">{{ $incident->impactStatement() }}</p>
        </section>

        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">Recommended next steps</h2>
            @php
                $incidentDiagnostics = \App\Ops\Diagnostics\DiagnosticEngine::runnableForEvents($timeline);
            @endphp
            @if($incidentDiagnostics !== [])
                <p class="text-xs text-slate-400 mb-3">Union of the diagnostics recommended by this incident's member events — run them from here with the incident as context.</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($incidentDiagnostics as $diagnostic)
                        @if(\App\Ops\Support\OpsAccessContext::canRunDiagnostics(auth()->user()))
                            <form method="POST" action="{{ route('ops.diagnostics.run') }}" data-busy data-busy-label="Running…">
                                @csrf
                                <input type="hidden" name="diagnostic" value="{{ $diagnostic }}">
                                <input type="hidden" name="incident" value="{{ $incident->id }}">
                                @if($incident->ops_application_id)<input type="hidden" name="application" value="{{ $incident->ops_application_id }}">@endif
                                <button class="btn btn-sm btn-ops-emerald-ghost whitespace-nowrap" title="{{ \App\Ops\Diagnostics\DiagnosticRegistry::get($diagnostic)['description'] ?? '' }}">
                                    ▶ {{ \App\Ops\Diagnostics\DiagnosticRegistry::label($diagnostic) }}
                                </button>
                            </form>
                        @else
                            <span class="btn btn-sm btn-ops-muted">{{ \App\Ops\Diagnostics\DiagnosticRegistry::label($diagnostic) }} — viewer (read-only)</span>
                        @endif
                    @endforeach
                </div>
            @else
                <p class="text-xs text-slate-500 mb-3">No specific diagnostics recommended — inspect the timeline events.</p>
            @endif
            <a href="{{ route('ops.diagnostics.index') }}" class="inline-block text-xs text-slate-500 hover:text-slate-300 mt-3">Browse all diagnostics →</a>
        </section>
    </div>

    {{-- ── Side column ────────────────────────────────────────────────── --}}
    <div class="space-y-6">
        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">Related</h2>
            <dl class="space-y-2 text-xs">
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500">Application</dt>
                    <dd class="text-slate-200">{{ $incident->application?->name ?? '—' }}</dd>
                </div>
                @if(data_get($incident->context, 'deployment_uuid'))
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500">Deployment</dt>
                        <dd class="text-slate-200 font-mono text-xs">{{ \Illuminate\Support\Str::limit(data_get($incident->context, 'deployment_uuid'), 14) }}</dd>
                    </div>
                @endif
                @if(data_get($incident->context, 'commit'))
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500">Commit</dt>
                        <dd class="text-slate-200 font-mono text-xs">{{ \Illuminate\Support\Str::limit(data_get($incident->context, 'commit'), 10, '') }}</dd>
                    </div>
                @endif
                @if(data_get($incident->context, 'server'))
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500">Server</dt>
                        <dd class="text-slate-200 truncate max-w-[160px]" title="{{ data_get($incident->context, 'server') }}">{{ data_get($incident->context, 'server') }}</dd>
                    </div>
                @endif
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500">First event</dt>
                    <dd class="text-slate-200">{{ $incident->first_event_at?->format('M j, H:i') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500">Last activity</dt>
                    <dd class="text-slate-200">{{ $incident->last_event_at?->format('M j, H:i') ?? '—' }}</dd>
                </div>
                @if($incident->acknowledged_at)
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500">Acknowledged</dt>
                        <dd class="text-slate-200">{{ $incident->acknowledged_at->diffForHumans() }}</dd>
                    </div>
                @endif
                @if($incident->resolved_at)
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500">Resolved</dt>
                        <dd class="text-slate-200">{{ $incident->resolved_at->diffForHumans() }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">How correlation works</h2>
            <p class="text-xs text-slate-500 leading-relaxed">
                Events on the same application within a 30-minute window join one incident. A deployment, build or
                migration event up to 60 minutes earlier becomes the root-cause candidate (high confidence).
                Correlation runs every 5 minutes plus immediately for critical errors. Root causes are ranked
                candidates — verify with diagnostics before acting.
            </p>
        </section>
    </div>
</div>
@endsection
