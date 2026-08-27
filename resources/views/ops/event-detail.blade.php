@extends('ops.layout')

@section('title', $event->title)

@section('content')
<div class="mb-4">
    <a href="{{ route('ops.events', ['status' => 'active']) }}" class="text-xs text-slate-500 hover:text-slate-300">← Back to events</a>
</div>

@php
    $sev = [
        'critical' => ['text' => 'CRITICAL', 'chip' => 'bg-red-950/60 text-red-300 border-red-700/60'],
        'error'    => ['text' => 'ERROR',    'chip' => 'bg-orange-950/50 text-orange-300 border-orange-700/50'],
        'warning'  => ['text' => 'WARNING',  'chip' => 'bg-amber-950/50 text-amber-300 border-amber-700/50'],
        'info'     => ['text' => 'INFO',     'chip' => 'bg-slate-800/60 text-slate-300 border-slate-600/50'],
    ][$event->severity] ?? ['text' => strtoupper($event->severity), 'chip' => 'bg-slate-800/60 text-slate-300 border-slate-600/50'];
@endphp

{{-- ── Header ─────────────────────────────────────────────────────────── --}}
<div class="rounded-xl border {{ $sev['chip'] }} border-slate-800 p-5 mb-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1.5">
                <span class="text-[10px] font-bold px-2 py-1 rounded border {{ $sev['chip'] }}">{{ $sev['text'] }}</span>
                <span class="text-[10px] font-mono px-2 py-1 rounded bg-slate-800 text-slate-400">{{ $event->category }}</span>
                <span class="text-[10px] px-2 py-1 rounded bg-slate-800 text-slate-400 uppercase">{{ $event->source }}</span>
                @if($event->status !== 'open')<span class="text-[10px] px-2 py-1 rounded bg-slate-900 text-slate-500 border border-slate-800">{{ $event->status }}</span>@endif
            </div>
            <h1 class="text-2xl font-semibold text-slate-50">{{ $event->title }}</h1>
        </div>
        <div class="text-right text-xs text-slate-400 space-y-0.5">
            <div><span class="text-slate-600">Event #</span>{{ $event->id }}</div>
            <div><span class="text-slate-600">Environment:</span> {{ $event->environment ?? '—' }}</div>
            <div><span class="text-slate-600">Last seen:</span> {{ $event->last_seen_at?->diffForHumans() }}</div>
        </div>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-6">

    {{-- ── Main column: the operational story ─────────────────────────── --}}
    <div class="lg:col-span-2 space-y-6">

        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 mb-3">What happened</h2>
            @if($event->message)
                <pre class="text-xs text-slate-300 bg-slate-950/70 rounded-lg border border-slate-800 p-3 overflow-x-auto whitespace-pre-wrap font-mono">{{ $event->message }}</pre>
            @else
                <p class="text-sm text-slate-400">No detail message was recorded for this event.</p>
            @endif
        </section>

        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 mb-3">Why it matters</h2>
            <p class="text-sm text-slate-300">{{ $event->impactStatement() }}</p>
            @if($event->severity === 'critical')
                <p class="text-xs text-red-400 mt-2">Classified CRITICAL — this event represents (or will quickly become) user-visible breakage.</p>
            @endif
        </section>

        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 mb-3">Likely causes</h2>
            <ul class="space-y-2">
                @forelse($event->likelyCauses() as $cause)
                    <li class="text-sm text-slate-300 flex gap-2.5">
                        <span class="text-amber-400 mt-0.5">▸</span>
                        <span>{{ $cause }}</span>
                    </li>
                @empty
                    <li class="text-sm text-slate-400">No pattern matched — inspect the message and context below; the classifier will learn from future occurrences.</li>
                @endforelse
            </ul>
            <p class="text-[10px] text-slate-600 mt-3">Causes are ranked possibilities, not certainties — verify with diagnostics before acting.</p>
        </section>

        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 mb-3">Recommended next steps</h2>
            @php
                $runnable = \App\Ops\Diagnostics\DiagnosticEngine::runnableRecommended($event->recommendedDiagnostics());
            @endphp
            @if($runnable !== [])
                <p class="text-xs text-slate-400 mb-3">Run a check with one click — read-only, audited, results explained in plain language.</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($runnable as $diagnostic)
                        @if(\App\Ops\Support\OpsAccessContext::canRunDiagnostics(auth()->user()))
                            <form method="POST" action="{{ route('ops.diagnostics.run') }}">
                                @csrf
                                <input type="hidden" name="diagnostic" value="{{ $diagnostic }}">
                                <input type="hidden" name="event" value="{{ $event->id }}">
                                @if($event->ops_application_id)<input type="hidden" name="application" value="{{ $event->ops_application_id }}">@endif
                                <button class="text-xs px-3 py-2 rounded-lg border border-emerald-700/60 bg-emerald-950/40 text-emerald-300 hover:bg-emerald-900/60 font-medium transition" title="{{ \App\Ops\Diagnostics\DiagnosticRegistry::get($diagnostic)['description'] ?? '' }}">
                                    ▶ {{ \App\Ops\Diagnostics\DiagnosticRegistry::label($diagnostic) }}
                                </button>
                            </form>
                        @else
                            <span class="text-xs px-3 py-2 rounded-lg border border-slate-800 text-slate-500">{{ \App\Ops\Diagnostics\DiagnosticRegistry::label($diagnostic) }} — viewer (read-only)</span>
                        @endif
                    @endforeach
                </div>
                <a href="{{ route('ops.diagnostics.index') }}" class="inline-block text-[11px] text-slate-500 hover:text-slate-300 mt-3">Browse all diagnostics →</a>
            @else
                <p class="text-xs text-slate-500 mb-3">No specific diagnostics recommended for this error class — the general catalog may still help.</p>
                @if(\App\Ops\Support\OpsAccessContext::canRunDiagnostics(auth()->user()))
                    <a href="{{ route('ops.diagnostics.index') }}" class="inline-flex items-center gap-1.5 text-xs px-3 py-2 rounded-lg border border-emerald-700/60 bg-emerald-950/40 text-emerald-300 hover:bg-emerald-900/60 font-medium">▶ Run: Recent errors</a>
                @endif
            @endif
        </section>

        @if($related->isNotEmpty())
        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 mb-3">Related events on the same application</h2>
            <p class="text-[11px] text-slate-500 mb-3">Events from this application near this event's window. When they share a cause, the correlation engine groups them into one incident.</p>
            <div class="divide-y divide-slate-800/70 rounded-lg border border-slate-800 overflow-hidden">
                @foreach($related as $rel)
                    <a href="{{ route('ops.events.show', $rel) }}" class="block p-3 hover:bg-slate-900/80 text-sm">
                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded mr-2 {{ $rel->severity === 'critical' ? 'bg-red-950/60 text-red-300' : ($rel->severity === 'error' ? 'bg-orange-950/50 text-orange-300' : 'bg-amber-950/50 text-amber-300') }}">{{ strtoupper($rel->severity) }}</span>
                        <span class="text-slate-200">{{ $rel->title }}</span>
                        <span class="text-[10px] text-slate-500 ml-2">{{ $rel->last_seen_at?->diffForHumans() }}</span>
                    </a>
                @endforeach
            </div>
        </section>
        @endif

        {{-- Technical context — secondary by design --}}
        <details class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <summary class="text-[11px] font-bold uppercase tracking-wider text-slate-500 cursor-pointer select-none">Technical context (already redacted)</summary>
            <div class="mt-4 space-y-4">
                <div>
                    <h3 class="text-[10px] uppercase tracking-wider text-slate-500 mb-1.5">Facts</h3>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-xs">
                        <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Application</dt><dd class="text-slate-200">{{ $event->application?->name ?? 'Unattributed' }}</dd></div>
                        <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">First seen</dt><dd class="text-slate-200">{{ $event->first_seen_at?->format('M j, H:i') ?? '—' }}</dd></div>
                        <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Last seen</dt><dd class="text-slate-200">{{ $event->last_seen_at?->format('M j, H:i') ?? '—' }}</dd></div>
                        <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Occurrences</dt><dd class="text-slate-200">{{ $event->occurrence_count }} this episode ({{ $event->total_count }} all-time)</dd></div>
                        <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Source</dt><dd class="text-slate-200">{{ $event->source }}</dd></div>
                        <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Fingerprint</dt><dd class="text-slate-200 font-mono text-[10px]">{{ \Illuminate\Support\Str::limit($event->fingerprint, 16) }}</dd></div>
                    </dl>
                </div>

                @if(data_get($event->context, 'http'))
                    <div>
                        <h3 class="text-[10px] uppercase tracking-wider text-slate-500 mb-1.5">HTTP context</h3>
                        <pre class="text-[11px] text-slate-400 bg-slate-950/70 rounded border border-slate-800 p-3 overflow-x-auto font-mono">{{ json_encode(data_get($event->context, 'http'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                @endif

                @if(data_get($event->context, 'stack'))
                    <div>
                        <h3 class="text-[10px] uppercase tracking-wider text-slate-500 mb-1.5">Stack excerpt (first frames)</h3>
                        <pre class="text-[11px] text-slate-400 bg-slate-950/70 rounded border border-slate-800 p-3 overflow-x-auto font-mono">{{ implode("\n", data_get($event->context, 'stack', [])) }}</pre>
                    </div>
                @endif

                @if($event->context && data_get($event->context, 'http') === null && data_get($event->context, 'stack') === null)
                    <div>
                        <h3 class="text-[10px] uppercase tracking-wider text-slate-500 mb-1.5">Context</h3>
                        <pre class="text-[11px] text-slate-400 bg-slate-950/70 rounded border border-slate-800 p-3 overflow-x-auto font-mono">{{ json_encode($event->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                @endif

                <p class="text-[10px] text-slate-600">Full stack traces remain in Sentry and the daily log files — this store keeps redacted excerpts only.</p>
            </div>
        </details>
    </div>

    {{-- ── Side column: where / when / how often ───────────────────────── --}}
    <div class="space-y-6">
        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-3">Where it happened</h2>
            <div class="text-sm text-slate-200 font-medium flex items-center gap-2">
                {{ $event->application?->name ?? 'Unattributed' }}
                @if($event->application?->is_self)<span class="text-[9px] px-1.5 py-0.5 rounded bg-cyan-900/50 text-cyan-300 border border-cyan-700/40 font-semibold">SELF</span>@endif
            </div>
            @if($event->application)
                <dl class="mt-3 space-y-1.5 text-xs">
                    <div class="flex justify-between"><dt class="text-slate-500">Kind</dt><dd class="text-slate-300">{{ $event->application->kind }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Environment</dt><dd class="text-slate-300">{{ $event->application->environment }}</dd></div>
                    @if($event->application->url)
                        <div class="flex justify-between"><dt class="text-slate-500">URL</dt><dd class="text-slate-300 truncate max-w-[140px] font-mono text-[10px]">{{ $event->application->url }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-slate-500">Coolify status</dt><dd class="text-slate-300 font-mono text-[10px]">{{ $event->application->status }}</dd></div>
                </dl>
                <a href="{{ route('ops.applications') }}" class="text-[11px] text-emerald-400 hover:text-emerald-300 mt-3 inline-block">View application →</a>
            @endif
        </section>

        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-3">How often</h2>
            <div class="text-3xl font-bold text-slate-100">{{ $event->occurrence_count }}</div>
            <p class="text-xs text-slate-400 mt-1">
                occurrences since {{ $event->first_seen_at?->diffForHumans() }}<br>
                <span class="text-slate-600">{{ $event->total_count }} all-time (across episodes)</span>
            </p>
        </section>

        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-3">What changed</h2>
            @php
                $commit = data_get($event->context, 'commit');
                $deployment = data_get($event->context, 'deployment_uuid') ?: data_get($event->context, 'deployment_status');
            @endphp
            @if($commit || $deployment)
                <ul class="text-xs text-slate-300 space-y-1.5">
                    @if($commit)<li>Commit <span class="font-mono text-slate-200">{{ \Illuminate\Support\Str::limit($commit, 10, '') }}</span> is associated with this event.</li>@endif
                    @if($deployment)<li>Deployment context: <span class="font-mono">{{ $deployment }}</span></li>@endif
                </ul>
            @else
                <p class="text-xs text-slate-400">No deployment or commit correlated with this event yet. The correlation engine links recent deployments automatically when they share the window.</p>
            @endif
            @if($event->incident)
                <a href="{{ route('ops.incidents.show', $event->incident) }}" class="inline-block mt-3 text-xs text-fuchsia-300 hover:text-fuchsia-200">This event is part of incident #{{ $event->incident->id }} →</a>
            @endif
        </section>
    </div>
</div>
@endsection
