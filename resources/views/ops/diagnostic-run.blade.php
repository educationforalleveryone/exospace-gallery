@extends('ops.layout')

@section('title', 'Diagnostic run — '.($definition['label'] ?? $run->diagnostic_id))

@section('content')
<div class="mb-4 flex items-center justify-between gap-4">
    <a href="{{ route('ops.diagnostics.index') }}" class="text-xs text-slate-500 hover:text-slate-300">← All diagnostics</a>
    @if(\App\Ops\Support\OpsAccessContext::canRunDiagnostics(auth()->user()))
        <form method="POST" action="{{ route('ops.diagnostics.run') }}">
            @csrf
            <input type="hidden" name="diagnostic" value="{{ $run->diagnostic_id }}">
            @if($run->ops_application_id)<input type="hidden" name="application" value="{{ $run->ops_application_id }}">@endif
            <button class="px-4 py-2 rounded-lg bg-emerald-700/80 hover:bg-emerald-600 text-xs font-medium">Run again</button>
        </form>
    @endif
</div>

@php
    $statusStyles = [
        'healthy'      => ['banner' => 'bg-emerald-950/50 border-emerald-700/60', 'chip' => 'bg-emerald-900/70 text-emerald-300 border-emerald-600/60', 'text' => 'DIAGNOSTIC PASSED'],
        'degraded'     => ['banner' => 'bg-amber-950/40 border-amber-700/60',     'chip' => 'bg-amber-900/70 text-amber-300 border-amber-600/60',     'text' => 'ATTENTION'],
        'failed'       => ['banner' => 'bg-red-950/50 border-red-700/60',         'chip' => 'bg-red-900/70 text-red-300 border-red-600/60',           'text' => 'FAILED'],
        'inconclusive' => ['banner' => 'bg-slate-900/60 border-slate-700/60',     'chip' => 'bg-slate-800 text-slate-300 border-slate-600/60',        'text' => 'INCONCLUSIVE'],
    ][$run->status] ?? ['banner' => 'bg-slate-900/60 border-slate-700/60', 'chip' => 'bg-slate-800 text-slate-300 border-slate-600/60', 'text' => strtoupper($run->status)];

    $findingIcons = [
        'pass' => ['icon' => '✓', 'class' => 'text-emerald-400 bg-emerald-950/60 border-emerald-800/60'],
        'warn' => ['icon' => '!', 'class' => 'text-amber-400 bg-amber-950/60 border-amber-800/60'],
        'fail' => ['icon' => '✗', 'class' => 'text-red-400 bg-red-950/60 border-red-800/60'],
        'skip' => ['icon' => '–', 'class' => 'text-slate-500 bg-slate-900/60 border-slate-700/60'],
    ];
@endphp

{{-- ── Status banner ─────────────────────────────────────────────────── --}}
<div class="rounded-xl border {{ $statusStyles['banner'] }} p-5 mb-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1.5">
                <span class="status {{ $statusStyles['chip'] }}">{{ $statusStyles['text'] }}</span>
                <span class="text-xs px-2 py-1 rounded bg-slate-800/80 text-slate-400 font-mono">{{ $run->diagnostic_id }}</span>
                <span class="text-xs px-2 py-1 rounded bg-slate-800/80 text-slate-400">run #{{ $run->id }}</span>
            </div>
            <h1 class="text-2xl font-semibold text-slate-50">{{ $definition['label'] ?? $run->diagnostic_id }}</h1>
            <p class="text-sm text-slate-300 mt-1.5">{{ $run->summary }}</p>
        </div>
        <div class="text-right text-xs text-slate-400 space-y-0.5">
            <div>Completed {{ $run->created_at?->diffForHumans() }}</div>
            <div>Duration: <span class="font-mono">{{ $run->duration_ms }} ms</span></div>
        </div>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-6">

    {{-- ── Main column ─────────────────────────────────────────────────── --}}
    <div class="lg:col-span-2 space-y-6">

        {{-- Findings --}}
        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-4">What was checked</h2>
            @if(empty($run->findings))
                <p class="text-sm text-slate-400">No individual checks were recorded for this run.</p>
            @else
                <ul class="space-y-3">
                    @foreach($run->findings as $finding)
                        @php($style = $findingIcons[$finding['status']] ?? $findingIcons['skip'])
                        <li class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded flex items-center justify-center border text-xs font-bold {{ $style['class'] }}">{{ $style['icon'] }}</span>
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-slate-200">{{ $finding['label'] }}</div>
                                <div class="text-xs text-slate-400 leading-relaxed mt-0.5 whitespace-pre-wrap break-words">{{ $finding['detail'] }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Interpretation --}}
        @if($run->interpretation)
        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">What this means</h2>
            <p class="text-sm text-slate-300 leading-relaxed">{{ $run->interpretation }}</p>
        </section>
        @endif

        {{-- Next steps --}}
        @if(!empty($run->next_steps))
        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">Suggested next steps</h2>
            <ul class="space-y-2.5">
                @foreach($run->next_steps as $step)
                    @if(\App\Ops\Diagnostics\DiagnosticRegistry::has($step))
                        <li>
                            @if(\App\Ops\Support\OpsAccessContext::canRunDiagnostics(auth()->user()))
                                <form method="POST" action="{{ route('ops.diagnostics.run') }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="diagnostic" value="{{ $step }}">
                                    @if($run->ops_application_id)<input type="hidden" name="application" value="{{ $run->ops_application_id }}">@endif
                                    <button class="text-xs px-3 py-1.5 rounded-lg border border-emerald-700/60 bg-emerald-950/40 text-emerald-300 hover:bg-emerald-900/60 font-medium transition">
                                        ▶ Run: {{ \App\Ops\Diagnostics\DiagnosticRegistry::label($step) }}
                                    </button>
                                </form>
                            @else
                                <span class="text-xs px-3 py-1.5 rounded-lg border border-slate-800 text-slate-500">{{ \App\Ops\Diagnostics\DiagnosticRegistry::label($step) }} — operator-only</span>
                            @endif
                        </li>
                    @else
                        <li class="text-sm text-slate-300 flex gap-2.5">
                            <span class="text-amber-400 mt-0.5">▸</span>
                            <span>{{ $step }}</span>
                        </li>
                    @endif
                @endforeach
            </ul>
        </section>
        @endif
    </div>

    {{-- ── Side column ─────────────────────────────────────────────────── --}}
    <div class="space-y-6">
        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">Run details</h2>
            <dl class="space-y-2 text-xs">
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Diagnostic</dt><dd class="text-slate-200 font-mono text-xs">{{ $run->diagnostic_id }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Application</dt><dd class="text-slate-200">{{ $run->application?->name ?? 'Control plane (self)' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Run by</dt><dd class="text-slate-200">{{ $run->actor?->email ?? 'system' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Trigger</dt><dd class="text-slate-200">{{ $run->sourceLabel() }}</dd></div>
                @if($run->source === 'event' && $run->source_id)
                    <a href="{{ route('ops.events.show', $run->source_id) }}" class="inline-block text-emerald-400 hover:text-emerald-300 text-xs">View the triggering event →</a>
                @endif
                @if($run->source === 'incident' && $run->source_id)
                    <a href="{{ route('ops.incidents.show', $run->source_id) }}" class="inline-block text-emerald-400 hover:text-emerald-300 text-xs">View the incident →</a>
                @endif
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Completed</dt><dd class="text-slate-200">{{ $run->created_at?->format('M j, H:i:s') }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Duration</dt><dd class="text-slate-200 font-mono">{{ $run->duration_ms }} ms</dd></div>
            </dl>
        </section>

        @if($definition)
        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">About this diagnostic</h2>
            <p class="text-xs text-slate-500 leading-relaxed">{{ $definition['description'] }}</p>
        </section>
        @endif

        <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Safety</h2>
            <p class="text-xs text-slate-500 leading-relaxed">
                This check is read-only: it executes a fixed, allow-listed routine from the control plane's own code —
                no shell commands, no arbitrary SQL, no container access. Its output was redacted before storage and
                the run is recorded in the admin audit log.
            </p>
        </section>
    </div>
</div>
@endsection
