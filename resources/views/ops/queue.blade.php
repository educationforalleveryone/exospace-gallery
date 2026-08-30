@extends('ops.layout')

@section('title', 'Queue — Failed jobs')

@section('content')
<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-semibold">Queue — failed jobs</h1>
        <p class="text-xs text-slate-400 mt-1 max-w-2xl leading-relaxed">
            Every job that exhausted its retry attempts, newest first. Retrying and deleting happen
            one job at a time from here — with password + typed confirmation — so the queue worker,
            the counts the digest reports and the diagnostics all stay honest.
        </p>
    </div>
    <a href="{{ route('ops.diagnostics.index') }}" class="text-xs text-slate-500 hover:text-slate-300 shrink-0">Run queue diagnostics →</a>
</div>

{{-- ── Summary strip ─────────────────────────────────────────────────── --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4">
        <div class="text-xs uppercase tracking-wider text-slate-500 mb-1">Failed jobs</div>
        <div class="text-2xl font-semibold {{ $summary['total'] > 10 ? 'text-amber-300' : 'text-slate-100' }}">{{ number_format($summary['total']) }}</div>
        <div class="text-xs text-slate-500 mt-0.5">warning above 10 · critical above 50</div>
    </div>
    <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4">
        <div class="text-xs uppercase tracking-wider text-slate-500 mb-1">Last 24 hours</div>
        <div class="text-2xl font-semibold {{ $summary['last_24h'] > 0 ? 'text-amber-300' : 'text-emerald-300' }}">{{ number_format($summary['last_24h']) }}</div>
        <div class="text-xs text-slate-500 mt-0.5">{{ $summary['last_24h'] > 0 ? 'actively failing' : 'nothing new failing' }}</div>
    </div>
    <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4">
        <div class="text-xs uppercase tracking-wider text-slate-500 mb-1">Oldest failure</div>
        <div class="text-2xl font-semibold text-slate-100">{{ $summary['oldest'] ?? '—' }}</div>
        <div class="text-xs text-slate-500 mt-0.5">{{ $summary['oldest'] !== null ? 'old failures inflate the count forever until handled' : 'no failures on record' }}</div>
    </div>
    <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4">
        <div class="text-xs uppercase tracking-wider text-slate-500 mb-1">Queues</div>
        <div class="text-2xl font-semibold text-slate-100">{{ count($queues) }}</div>
        <div class="text-xs text-slate-500 mt-0.5">{{ $queueFilter !== null ? 'filtered view — chips below' : 'click a chip to filter' }}</div>
    </div>
</div>

{{-- ── Queue filter chips ───────────────────────────────────────────── --}}
@if(count($queues) > 0)
<div class="flex flex-wrap items-center gap-2 mb-5">
    <a href="{{ route('ops.queue.index') }}"
       class="text-xs px-2.5 py-1 rounded-full border {{ $queueFilter === null ? 'bg-emerald-600/20 text-emerald-300 border-emerald-700/40' : 'bg-slate-900/60 text-slate-400 border-slate-700/60 hover:text-slate-200' }}">
        All · {{ number_format($summary['unfiltered_total']) }}
    </a>
    @foreach($queues as $chip)
        <a href="{{ route('ops.queue.index', ['queue' => $chip['queue']]) }}"
           class="text-xs px-2.5 py-1 rounded-full border font-mono {{ $queueFilter === $chip['queue'] ? 'bg-emerald-600/20 text-emerald-300 border-emerald-700/40' : 'bg-slate-900/60 text-slate-400 border-slate-700/60 hover:text-slate-200' }}">
            {{ $chip['queue'] }} · {{ number_format($chip['count']) }}
        </a>
    @endforeach
    @if($queueFilter !== null)
        <span class="text-xs text-slate-500">filtered — {{ number_format($jobs->total()) }} job(s) on queue "{{ $queueFilter }}"</span>
    @endif
</div>
@endif

{{-- ── Missing-table notice (fresh install before migrations) ────────── --}}
@if(! $tableAvailable)
<div class="rounded-lg border border-amber-800/60 bg-amber-950/30 px-4 py-3 text-sm text-amber-200/90">
    The <span class="font-mono text-xs">failed_jobs</span> table is not available on this database yet —
    it is created by Laravel's base migrations. Once migrations have run, this page shows every failed
    job on the platform. The queue diagnostic shows the same message until then.
</div>
@elseif($jobs->isEmpty())
<div class="rounded-lg border border-emerald-800/50 bg-emerald-950/20 px-4 py-8 text-center">
    <div class="text-sm text-emerald-300 font-medium">No failed jobs{{ $queueFilter !== null ? ' on queue "'.$queueFilter.'"' : '' }}.</div>
    <p class="text-xs text-slate-400 mt-1.5 max-w-md mx-auto leading-relaxed">
        Nothing has exhausted its retry attempts. If background work still seems missing, the cause is
        more likely upstream — jobs never dispatched — which the scheduler and queue diagnostics cover.
    </p>
</div>
@else
<div class="space-y-3">
    @foreach($jobs as $job)
        <div class="rounded-lg border border-slate-800 bg-slate-900/40 hover:border-slate-700 transition">
            {{-- Row header: always-visible facts --}}
            <div class="px-4 py-3 flex flex-wrap items-start gap-x-4 gap-y-2">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-medium text-slate-100 font-mono text-[13px]">{{ $job['job'] }}</span>
                        <span class="text-xs px-1.5 py-0.5 rounded bg-slate-800/80 text-slate-400 border border-slate-700/60 font-mono">queue: {{ $job['queue'] }}</span>
                        <span class="text-xs px-1.5 py-0.5 rounded bg-slate-800/80 text-slate-500 border border-slate-700/60 font-mono">{{ $job['connection'] }}</span>
                    </div>
                    @if($job['first_exception'] !== '')
                        <div class="text-xs text-red-300/90 mt-1.5 font-mono leading-relaxed break-all">{{ $job['first_exception'] }}</div>
                    @endif
                </div>
                <div class="text-right shrink-0">
                    <div class="text-xs text-slate-400">{{ $job['failed_at']?->diffForHumans() }}</div>
                    <div class="text-xs text-slate-600 font-mono mt-0.5" title="failed at {{ $job['failed_at']?->format('Y-m-d H:i:s') ?? '—' }}">{{ $job['failed_at']?->format('Y-m-d H:i') ?? '' }}</div>
                </div>
            </div>

            {{-- Details: payload + exception excerpt, behind a disclosure toggle --}}
            <details class="group border-t border-slate-800/70">
                <summary class="px-4 py-2 text-xs text-slate-500 hover:text-slate-300 cursor-pointer select-none">
                    Payload &amp; exception details — <span class="text-slate-600">payloads may contain application data</span>
                </summary>
                <div class="px-4 pb-3 pt-1 space-y-2">
                    <div>
                        <div class="text-xs uppercase tracking-wider text-slate-600 mb-1">Exception (first 2 000 chars)</div>
                        <pre class="text-xs text-slate-400 bg-slate-950/70 rounded-md border border-slate-800/80 p-3 overflow-x-auto whitespace-pre-wrap break-all">{{ $job['exception_excerpt'] !== '' ? $job['exception_excerpt'] : '—' }}</pre>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wider text-slate-600 mb-1">Payload (first 600 chars — the ONLY copy; it is deleted with a Forget)</div>
                        <pre class="text-xs text-slate-500 bg-slate-950/70 rounded-md border border-slate-800/80 p-3 overflow-x-auto whitespace-pre-wrap break-all">{{ $job['payload_excerpt'] !== '' ? $job['payload_excerpt'] : '—' }}</pre>
                    </div>
                    <div class="text-xs text-slate-600 font-mono">uuid: {{ $job['uuid'] }}</div>
                </div>
            </details>

            {{-- Actions: super-admin only, through the confirm pages — never a direct POST --}}
            @if(auth()->user()?->is_super_admin)
                @if($actionsEnabled)
                    <div class="px-4 py-2.5 border-t border-slate-800/70 flex flex-wrap items-center gap-2 bg-slate-900/30">
                        <a href="{{ route('ops.actions.confirm', ['action' => 'queue.retry', 'job' => $job['uuid']]) }}"
                           class="text-xs px-3 py-1.5 rounded-lg border border-emerald-700/60 bg-emerald-950/40 text-emerald-300 hover:bg-emerald-900/50 font-medium">
                            Retry… <span class="text-xs text-emerald-500/70 font-normal">runs the job again</span>
                        </a>
                        <a href="{{ route('ops.actions.confirm', ['action' => 'queue.forget', 'job' => $job['uuid']]) }}"
                           class="text-xs px-3 py-1.5 rounded-lg border border-red-800/60 bg-red-950/40 text-red-300 hover:bg-red-900/50 font-medium">
                            Forget… <span class="text-xs text-red-400/70 font-normal">deletes the only copy</span>
                        </a>
                        <span class="text-xs text-slate-600">both require password + typed phrase · audited · announced in Slack</span>
                    </div>
                @else
                    <div class="px-4 py-2.5 border-t border-slate-800/70 bg-slate-900/30">
                        <span class="text-xs text-slate-600">Retry/Forget are disabled on this deployment (OPS_ACTIONS_ENABLED=false) — the list stays fully readable.</span>
                    </div>
                @endif
            @endif
        </div>
    @endforeach
</div>

<div class="mt-4">{{ $jobs->links() }}</div>
@endif

<div class="mt-4 text-xs text-slate-600 space-y-1">
    <p>Retrying pushes the stored payload back onto the queue the job failed on (Laravel's own <span class="font-mono">queue:retry</span> path); forgetting deletes the row permanently (Laravel's <span class="font-mono">queue:forget</span>). Both act on ONE job per confirmation.</p>
    <p>The digest and the queue diagnostics page for this automatically — counts drop on their next pass. Bulk operations (retry all, flush) are deliberately not offered here: one job at a time is the point.</p>
</div>
@endsection
