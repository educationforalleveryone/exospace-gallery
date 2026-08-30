@extends('ops.layout')

@section('title', 'Actions')

@section('content')
<div class="mb-4">
    <h1 class="text-xl font-semibold">Actions</h1>
    <p class="text-xs text-slate-400 mt-1">
        The only operations that change state outside the control plane — deliberately few, deliberately explicit.
    </p>
</div>

@if(! $enabled)
<div class="mb-6 rounded-lg border border-red-800/60 bg-red-950/40 px-4 py-3 text-sm text-red-300">
    Actions are <strong>disabled</strong> on this deployment (<span class="font-mono">OPS_ACTIONS_ENABLED=false</span>).
    Diagnostics remain fully available — they are read-only.
</div>
@endif

{{-- ── Risk none: refresh ─────────────────────────────────────────────── --}}
<section class="mb-8">
    <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">Safe — no confirmation needed</h2>
    <div class="grid md:grid-cols-2 gap-4">
        @foreach($actions as $id => $definition)
            @if($definition['risk'] !== 'none')@continue @endif
            <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-5">
                <div class="flex items-start justify-between gap-2 mb-1.5">
                    <h3 class="text-sm font-semibold text-slate-100">{{ $definition['label'] }}</h3>
                    <span class="text-xs px-1.5 py-0.5 rounded bg-emerald-950/50 text-emerald-300 border border-emerald-800/50 font-semibold shrink-0">RISK: NONE</span>
                </div>
                <p class="text-xs text-slate-400 leading-relaxed mb-4">{{ $definition['description'] }}</p>
                @if($enabled)
                    <form method="POST" action="{{ route('ops.actions.execute', $id) }}">
                        @csrf
                        <button class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-xs font-medium text-slate-100 transition">Refresh now</button>
                    </form>
                @else
                    <span class="text-xs text-slate-600">Disabled by kill switch.</span>
                @endif
            </div>
        @endforeach
    </div>
</section>

{{-- ── Risk elevated: infrastructure actions ──────────────────────────── --}}
<section class="mb-8">
    <h2 class="text-xs font-bold uppercase tracking-wider text-amber-400 mb-3">Elevated — password + typed confirmation required</h2>
    <div class="rounded-lg border border-amber-900/40 bg-amber-950/20 px-4 py-3 text-xs text-amber-200/90 mb-4 leading-relaxed">
        These actions change infrastructure or re-apply external events. Every execution requires your password and a
        typed confirmation phrase, is recorded in the audit log, and is announced in the ops Slack channel. They are
        deliberately NOT one-click. Dangerous operations (stop application, delete database, run migrations) are
        intentionally absent — those stay in Coolify and the deploy pipeline where their full context lives.
    </div>

    <div class="grid md:grid-cols-2 gap-4 mb-6">
        {{-- Restart card with app picker --}}
        @foreach($actions as $id => $definition)
            @if($definition['risk'] !== 'elevated')@continue @endif
            @if($id === 'app.restart')
                <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-5 flex flex-col">
                    <div class="flex items-start justify-between gap-2 mb-1.5">
                        <h3 class="text-sm font-semibold text-slate-100">{{ $definition['label'] }}</h3>
                        <span class="text-xs px-1.5 py-0.5 rounded bg-amber-950/60 text-amber-300 border border-amber-800/50 font-semibold shrink-0">RISK: ELEVATED</span>
                    </div>
                    <p class="text-xs text-slate-400 leading-relaxed mb-4 flex-1">{{ $definition['description'] }}</p>
                    @if($enabled)
                        <form method="GET" action="{{ route('ops.actions.confirm', $id) }}" class="flex gap-2">
                            <select name="app" required class="flex-1 bg-slate-900 border border-slate-700 rounded-lg text-xs text-slate-200 px-3 py-2 focus:border-emerald-600 outline-none">
                                <option value="">Pick an application…</option>
                                @forelse($coolifyApps as $app)
                                    <option value="{{ $app->id }}">{{ $app->name }}{{ $app->is_self ? ' (control plane host)' : '' }}</option>
                                @empty
                                @endforeach
                            </select>
                            @if($coolifyApps->isEmpty())
                                <p class="text-xs text-amber-400 mt-2">No applications reported by Coolify. Run a sync from Applications, then retry — the restart action needs a live app.</p>
                            @endif
                            <button class="px-4 py-2 rounded-lg bg-amber-700/80 hover:bg-amber-600 text-xs font-medium text-slate-50 transition shrink-0">Continue…</button>
                        </form>
                    @else
                        <span class="text-xs text-slate-600">Disabled by kill switch.</span>
                    @endif
                </div>
            @endif
        @endforeach

        {{-- Webhook replay card: explained, driven by the failed-rows table below --}}
        @foreach($actions as $id => $definition)
            @if($id !== 'webhook.replay')@continue @endif
            <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-5 flex flex-col">
                <div class="flex items-start justify-between gap-2 mb-1.5">
                    <h3 class="text-sm font-semibold text-slate-100">{{ $definition['label'] }}</h3>
                    <span class="text-xs px-1.5 py-0.5 rounded bg-amber-950/60 text-amber-300 border border-amber-800/50 font-semibold shrink-0">RISK: ELEVATED</span>
                </div>
                <p class="text-xs text-slate-400 leading-relaxed mb-4 flex-1">
                    {{ $definition['description'] }}
                    @if($failedWebhooks->isEmpty())
                        — currently there are <span class="text-emerald-400">no failed webhooks</span> to replay.
                    @else
                        — <span class="text-amber-300">{{ $failedWebhooks->count() }} failed webhook(s)</span> listed below.
                    @endif
                </p>
                @if(! $enabled)
                    <span class="text-xs text-slate-600">Disabled by kill switch.</span>
                @endif
            </div>
        @endforeach

        {{-- Iteration 10 — queue cards: the failed-jobs lifecycle. The per-job --}}
        {{-- buttons live on the queue page; the hub shows the pointer.       --}}
        @foreach(['queue.retry', 'queue.forget'] as $queueId)
            @php($queueDefinition = $actions[$queueId] ?? null)
            @if($queueDefinition === null)@continue @endif
            <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-5 flex flex-col">
                <div class="flex items-start justify-between gap-2 mb-1.5">
                    <h3 class="text-sm font-semibold text-slate-100">{{ $queueDefinition['label'] }}</h3>
                    <span class="text-xs px-1.5 py-0.5 rounded {{ $queueId === 'queue.forget' ? 'bg-red-950/60 text-red-300 border border-red-800/50' : 'bg-amber-950/60 text-amber-300 border border-amber-800/50' }} font-semibold shrink-0">RISK: ELEVATED</span>
                </div>
                <p class="text-xs text-slate-400 leading-relaxed mb-4 flex-1">
                    {{ $queueDefinition['description'] }}
                    @if($failedJobCount === null)
                        — the failed-jobs table is <span class="text-slate-500">not available</span> on this database yet.
                    @elseif($failedJobCount === 0)
                        — currently there are <span class="text-emerald-400">no failed jobs</span> to handle.
                    @else
                        — <span class="text-amber-300">{{ $failedJobCount }} failed job(s)</span> on record.
                    @endif
                </p>
                @if($failedJobCount !== null && $failedJobCount > 0)
                    <a href="{{ route('ops.queue.index') }}" class="text-xs px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 font-medium text-slate-100 transition text-center">Open the failed-jobs list →</a>
                @else
                    <a href="{{ route('ops.queue.index') }}" class="text-xs text-slate-500 hover:text-slate-300">View the queue page anyway</a>
                @endif
                @if(! $enabled)
                    <span class="text-xs text-slate-600 mt-2">Disabled by kill switch.</span>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Failed webhooks panel (the replay targets) --}}
    @if($failedWebhooks->isNotEmpty())
    <div class="overflow-x-auto rounded-lg border border-slate-800">
        <table class="w-full text-sm">
            <thead class="bg-slate-900/80 text-slate-400 text-xs uppercase tracking-wider">
                <tr>
                    <th class="text-left px-4 py-3">Webhook</th>
                    <th class="text-left px-4 py-3">Type</th>
                    <th class="text-left px-4 py-3">Invoice</th>
                    <th class="text-left px-4 py-3">Status</th>
                    <th class="text-left px-4 py-3">Updated</th>
                    <th class="text-left px-4 py-3">Replays</th>
                    <th class="text-left px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/80">
                @foreach($failedWebhooks as $webhook)
                    <tr class="hover:bg-slate-900/60">
                        <td class="px-4 py-3 text-slate-300 font-mono text-xs">#{{ $webhook->id }}</td>
                        <td class="px-4 py-3 text-slate-200 text-xs">{{ $webhook->message_type ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-400 font-mono text-xs">{{ $webhook->invoice_id ?? '—' }}</td>
                        <td class="px-4 py-3"><span class="text-xs font-bold px-2 py-1 rounded bg-red-950/60 text-red-300 border border-red-800/50">FAILED</span></td>
                        <td class="px-4 py-3 text-slate-500 text-xs">{{ $webhook->updated_at?->diffForHumans() ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500 text-xs">{{ (int) ($webhook->replay_count ?? 0) }}×</td>
                        <td class="px-4 py-3">
                            @if($enabled && $webhook->payload)
                                <a href="{{ route('ops.actions.confirm', ['action' => 'webhook.replay', 'webhook' => $webhook->id]) }}" class="text-xs px-3 py-1.5 rounded-lg border border-amber-700/60 bg-amber-950/40 text-amber-300 hover:bg-amber-900/60 font-medium">Replay…</a>
                            @elseif(! $webhook->payload)
                                <span class="text-xs text-slate-600">no stored payload</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</section>

{{-- ── Recent executed actions (audit ledger view) ────────────────────── --}}
<section>
    <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">Recent executed actions</h2>
    <div class="overflow-x-auto rounded-lg border border-slate-800">
        <table class="w-full text-sm">
            <thead class="bg-slate-900/80 text-slate-400 text-xs uppercase tracking-wider">
                <tr>
                    <th class="text-left px-4 py-3">Action</th>
                    <th class="text-left px-4 py-3">Outcome</th>
                    <th class="text-left px-4 py-3">Target</th>
                    <th class="text-left px-4 py-3">Detail</th>
                    <th class="text-left px-4 py-3">When</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/80">
                @forelse($recentActions as $entry)
                    @php($outcome = data_get($entry->payload, 'outcome', '—'))
                    <tr class="hover:bg-slate-900/60">
                        <td class="px-4 py-3 text-slate-200 font-mono text-xs">{{ data_get($entry->payload, 'action', '?') }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-bold px-2 py-1 rounded border {{ $outcome === 'success' ? 'bg-emerald-950/60 text-emerald-300 border-emerald-800/50' : 'bg-red-950/60 text-red-300 border-red-800/50' }}">{{ strtoupper((string) $outcome) }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-400 text-xs">{{ $entry->target_type === \App\Ops\Models\OpsApplication::class ? 'application' : ($entry->target_type === \App\Models\ProcessedWebhook::class ? 'webhook' : '—') }} #{{ $entry->target_id }}</td>
                        <td class="px-4 py-3 text-slate-400 text-xs max-w-md"><span class="line-clamp-1">{{ data_get($entry->payload, 'message', '') }}</span></td>
                        <td class="px-4 py-3 text-slate-500 text-xs">{{ $entry->created_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500 text-sm">No actions have been executed yet.</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<div class="mt-4 text-xs text-slate-600 space-y-1">
    <p>Every action — success or failure — is audited (<span class="font-mono">ops.action.executed</span>), announced in Slack, and recorded in the control plane's own event timeline.</p>
    <p>Queue jobs are handled one at a time from the <a href="{{ route('ops.queue.index') }}" class="text-slate-400 hover:text-slate-200 underline underline-offset-2">failed-jobs page</a> — bulk retry/flush is deliberately absent.</p>
    <p>Need something more invasive (stop, redeploy, run migrations, scale)? That is Coolify's job — this control plane aggregates and diagnoses; it does not replace the deployment plane.</p>
</div>
@endsection
