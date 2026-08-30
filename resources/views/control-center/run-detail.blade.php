@extends('control-center.layout')

@section('title', "Run #{$run->id} · {$run->profile}")

@php
    $color = match ($run->status) {
        'passed' => 'text-emerald-400', 'failed' => 'text-red-400',
        'timed_out' => 'text-orange-400', default => 'text-slate-400',
    };
@endphp

@section('content')
@if ($run->status === 'running' || $run->status === 'queued')
    <meta http-equiv="refresh" content="6">
@endif

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">
            {{ $run->profile }} <span class="font-mono text-sm text-slate-500">#{{ $run->id }}</span>
        </h1>
        <p class="text-xs text-slate-500">
            env <b>{{ $run->environment }}</b> · trigger {{ $run->trigger }} · runner {{ $run->runner ?? '—' }}
            @if ($run->git_commit) · commit <span class="font-mono">{{ Str::limit($run->git_commit, 10, '') }}</span> @endif
            @if ($run->git_branch) · branch {{ $run->git_branch }} @endif
        </p>
    </div>
    <div class="text-right">
        <span class="text-3xl font-black {{ $color }}">{{ strtoupper($run->displayStatus()) }}</span>
        @if ($run->duration_ms)
            <div class="text-xs text-slate-500">wall clock {{ number_format($run->duration_ms / 1000, 1) }}s</div>
        @endif
    </div>
</div>

<div class="mb-6 flex flex-wrap gap-2 text-sm">
    <form method="POST" action="{{ route('control-center.profile.start', $run->profile) }}">
        @csrf
        <button class="btn btn-primary">[Run Again · {{ $run->profile }}]</button>
    </form>
    @if ($artifactPath)
        <a href="{{ route('control-center.run.artifact', $run) }}"
           class="btn btn-ops-ghost">[View Logs · JUnit]</a>
    @endif
    <a href="{{ route('control-center.runs', ['profile' => $run->profile]) }}"
       class="btn btn-ops-ghost">[Profile History]</a>
</div>

@if ($run->blocked_reason)
    <div class="mb-6 rounded-lg border border-amber-800 bg-amber-950/50 px-4 py-3 text-sm text-amber-200">
        <b>Honest status note:</b> {{ $run->blocked_reason }}
    </div>
@endif

<div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-6">
    @foreach ([['total',$run->total],['passed',$run->passed],['failed',$run->failed],
               ['errored',$run->errored],['skipped',$run->skipped],['duration',
               $run->duration_ms ? number_format($run->duration_ms/1000,1).'s' : '—']] as [$label,$value])
        <div class="rounded-lg border border-slate-800 bg-slate-900/60 p-3 text-center">
            <div class="text-xs uppercase tracking-wide text-slate-500">{{ $label }}</div>
            <div class="mt-1 text-xl font-semibold">{{ is_numeric($value) ? number_format($value) : $value }}</div>
        </div>
    @endforeach
</div>

@if ($run->failure_class)
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm {{
        $run->failure_class === 'infrastructure'
            ? 'border-sky-800 bg-sky-950/50 text-sky-200'
            : ($run->failure_class === 'mixed' ? 'border-amber-800 bg-amber-950/40 text-amber-200' : 'border-red-900 bg-red-950/40 text-red-200')
    }}">
        @if ($run->failure_class === 'infrastructure')
            🧰 <b>Test infrastructure failure.</b> The environment (DB / Redis / disk / config) broke — this is NOT an application regression. Fix the environment first.
        @elseif ($run->failure_class === 'mixed')
            ⚠️ Mixed: some environment failures and some application failures. Resolve infra noise before judging the app failures.
        @else
            🧨 Application failure(s): deterministic product behavior needs review.
        @endif
    </div>
@endif

<h2 class="mb-3 text-lg font-semibold">Failures &amp; Errors <span class="text-sm font-normal text-slate-500">({{ $failures->count() }})</span></h2>

@forelse ($failures as $case)
    @php $fc = $case->failureClass(); $hist = $history[$case->test_identifier] ?? null; @endphp
    <article class="mb-4 rounded-xl border border-slate-800 bg-slate-900/50 p-5">
        <header class="mb-2 flex flex-wrap items-center gap-2">
            <span class="rounded border px-1.5 py-0.5 text-xs font-bold uppercase {{
                $fc==='infrastructure' ? 'border-sky-700 text-sky-300' : ($fc==='application' ? 'border-red-700 text-red-300' : 'border-slate-600 text-slate-400') }}">
                {{ $fc ?? $case->status }}
            </span>
            <code class="text-sm font-semibold text-slate-200">{{ $case->test_identifier }}</code>
            @if ($case->data_set)<span class="text-xs text-slate-500">data-set: {{ $case->data_set }}</span>@endif
            <span class="ml-auto text-xs text-slate-500">{{ $case->time_ms !== null ? number_format($case->time_ms).'ms' : '' }}</span>
        </header>

        <pre class="mb-3 overflow-x-auto whitespace-pre-wrap rounded-md bg-slate-950/70 p-3 text-xs leading-relaxed text-red-200">{{ $case->message }}</pre>

        @if ($case->detail && trim($case->detail) !== trim((string) $case->message))
            <details class="mb-2 text-xs">
                <summary class="cursor-pointer text-slate-400 hover:text-slate-300">stack trace / full detail</summary>
                <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-md bg-black/40 p-3 text-xs text-slate-400">{{ $case->detail }}</pre>
            </details>
        @endif

        @if ($hist)
            <footer class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
                <span>executions: <b class="text-slate-300">{{ $hist['executions'] }}</b></span>
                @if ($hist['pass_rate'] !== null)
                    <span>pass rate (profile history): <b class="{{ $hist['pass_rate'] >= 90 ? 'text-emerald-400' : 'text-amber-400' }}">{{ $hist['pass_rate'] }}%</b></span>
                @endif
                @if ($hist['previous_pass'])
                    <span>last green: <b class="text-emerald-400">{{ \Carbon\Carbon::parse($hist['previous_pass'])->diffForHumans() }}</b></span>
                    @if ($hist['pass_rate'] !== null && $hist['pass_rate'] < 90)
                        <span class="text-purple-400">❄ flaky candidate — reliability tracked in Iteration 3</span>
                    @endif
                @else
                    <span class="text-red-400">🆕 first known appearance of this failure</span>
                @endif
            </footer>
        @endif
    </article>
@empty
    <div class="rounded-xl border border-emerald-900 bg-emerald-950/30 p-8 text-center text-emerald-300">
        ✅ Clean run — zero failing cases. Nothing to drill into.
    </div>
@endforelse
@endsection
