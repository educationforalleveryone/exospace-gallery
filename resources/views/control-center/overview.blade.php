@extends('control-center.layout')

@section('title', 'Overview')

@php
    $badge = [
        'passed' => 'bg-emerald-900/70 text-emerald-300 border-emerald-700',
        'failed' => 'bg-red-900/70 text-red-300 border-red-700',
        'running'=> 'bg-sky-900/70 text-sky-300 border-sky-700',
        'queued' => 'bg-slate-800 text-slate-300 border-slate-600',
        'timed_out' => 'bg-orange-900/70 text-orange-300 border-orange-700',
        'blocked' => 'bg-slate-800 text-slate-400 border-slate-700',
        'not_executed' => 'bg-slate-800 text-slate-400 border-slate-700',
        'cancelled' => 'bg-slate-800 text-slate-400 border-slate-700',
    ];
@endphp

@section('content')
<div class="mb-8 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Status Wall</h1>
        <p class="text-sm text-slate-500">
            @if ($git_commit)
                Build <span class="font-mono text-slate-300">{{ $git_commit }}</span> · {{ $git_branch }} · last activity {{ $lastActivity?->diffForHumans() }}
            @else
                No runs recorded yet — trigger a profile below or via GitHub Actions.
            @endif
        </p>
    </div>
    <div class="rounded-lg border border-violet-800 bg-violet-950/40 px-4 py-3 text-sm">
        <span class="mr-2 font-semibold">Release readiness</span>
        <span class="text-slate-400">evaluate from Iteration 3 (gates config already installed)</span>
    </div>
</div>

<div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
    @foreach ($profiles as $key => $meta)
        <section class="flex flex-col rounded-xl border border-slate-800 bg-slate-900/50 p-5">
            <header class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold">{{ $meta['icon'] }} {{ $meta['label'] }}</h2>
                    <p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ $meta['description'] }}</p>
                </div>
                @if ($latest = $meta['latest_run'])
                    <span class="shrink-0 rounded-md border px-2 py-1 text-xs font-semibold {{ $badge[$latest->status] ?? $badge['blocked'] }}">
                        {{ $latest->displayStatus() }}
                    </span>
                @else
                    <span class="shrink-0 rounded-md border px-2 py-1 text-xs text-slate-500 border-slate-700 bg-slate-800/60">NEVER RUN</span>
                @endif
            </header>

            @if ($latest && $latest->total > 0)
                <dl class="mb-3 grid grid-cols-4 gap-2 text-center text-xs">
                    <div class="rounded-md bg-slate-800/60 py-2"><dt class="text-slate-500">tests</dt><dd class="mt-0.5 font-semibold">{{ number_format($latest->total) }}</dd></div>
                    <div class="rounded-md bg-emerald-950/60 py-2"><dt class="text-emerald-600">pass</dt><dd class="mt-0.5 font-semibold text-emerald-300">{{ number_format($latest->passed) }}</dd></div>
                    <div class="rounded-md bg-red-950/60 py-2"><dt class="text-red-500">fail</dt><dd class="mt-0.5 font-semibold text-red-300">{{ $latest->failed + $latest->errored }}</dd></div>
                    <div class="rounded-md bg-slate-800/60 py-2"><dt class="text-slate-500">skip</dt><dd class="mt-0.5 font-semibold">{{ $latest->skipped }}</dd></div>
                </dl>
            @elseif($latest)
                <p class="mb-3 text-xs text-slate-500">{{ $latest->blocked_reason ?? 'No test cases executed.' }}</p>
            @endif

            {{-- Mini sparkline of the last 5 runs --}}
            @if (($meta['history'])->count() > 1)
                <div class="mb-4 flex items-end gap-1" title="Last {{ $meta['history']->count() }} runs (newest right)">
                    @foreach ($meta['history'] as $h)
                        @php $height = match(true){ $h->status==='passed' => 100, default => max(12, $h->passed > 0 ? (int)(90*$h->passed/max(1,$h->total)) : 10) }; @endphp
                        <span class="w-3 rounded-sm {{ str_starts_with($h->status,'pass') ? 'bg-emerald-600' : ($h->status==='failed' ? 'bg-red-600' : 'bg-slate-600') }}"
                              style="height:{{ $height }}%"></span>
                    @endforeach
                </div>
            @endif

            <footer class="mt-auto flex items-center gap-2 pt-2 text-xs">
                <a href="{{ route('control-center.runs', ['profile' => $key]) }}"
                   class="text-sky-400 hover:text-sky-300">history</a>
                <span class="text-slate-700">·</span>
                <form method="POST" action="{{ route('control-center.profile.start', $key) }}">
                    @csrf
                    <button type="submit"
                        class="rounded-md bg-violet-600 px-3 py-1.5 font-medium text-white hover:bg-violet-500 disabled:opacity-40">
                        Run {{ $meta['estimated_minutes'] ? "({$meta['estimated_minutes']} min)" : '' }}
                    </button>
                </form>
                <span class="ml-auto rounded border px-1.5 py-0.5 text-[10px] uppercase tracking-wide
                    {{ $meta['safety']==='prod-safe-read' ? 'border-teal-700 text-teal-400' : 'border-slate-700 text-slate-500' }}">
                    {{ $meta['safety'] }}
                </span>
            </footer>
        </section>
    @endforeach
</div>
@endsection
