@extends('ops.layout')

@section('title', 'Confirm — '.$definition['label'])

@section('content')
<div class="mb-4">
    @if($failedJob)
        <a href="{{ route('ops.queue.index') }}" class="text-xs text-slate-500 hover:text-slate-300">← Failed jobs</a>
    @else
        <a href="{{ route('ops.actions.index') }}" class="text-xs text-slate-500 hover:text-slate-300">← All actions</a>
    @endif
</div>

{{-- ── The explicit interstitial: nothing executes until this form succeeds ── --}}
<div class="max-w-3xl mx-auto">

    <div class="rounded-xl border border-amber-700/60 bg-amber-950/30 p-5 mb-6">
        <div class="flex items-center gap-2 mb-2">
            <span class="text-xs font-bold px-2 py-1 rounded bg-amber-900/70 text-amber-200 border border-amber-700/60">CONFIRMATION REQUIRED</span>
            <span class="text-xs font-bold px-2 py-1 rounded bg-amber-950/60 text-amber-300 border border-amber-800/50">RISK: ELEVATED</span>
        </div>
        <h1 class="text-2xl font-semibold text-slate-50">{{ $definition['label'] }}</h1>
        <p class="text-sm text-slate-300 mt-1.5">{{ $definition['description'] }}</p>
    </div>

    {{-- Target --}}
    <section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5 mb-6">
        <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">Target</h2>
        @if($application)
            <div class="text-sm text-slate-100 font-medium flex items-center gap-2">
                {{ $application->name }}
                @if($application->is_self)<span class="text-xs px-1.5 py-0.5 rounded bg-cyan-900/50 text-cyan-300 border border-cyan-700/40 font-semibold">SELF — CONTROL PLANE HOST</span>@endif
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1.5 text-xs">
                <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Kind</dt><dd class="text-slate-300">{{ $application->kind }}</dd></div>
                <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Coolify status</dt><dd class="text-slate-300 font-mono text-xs">{{ $application->status }}</dd></div>
                <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Health rollup</dt><dd class="text-slate-300">{{ $application->healthLabel() }}</dd></div>
                <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Resource UUID</dt><dd class="text-slate-300 font-mono text-xs">{{ \Illuminate\Support\Str::limit($application->provider_uuid ?? ($application->meta['coolify_uuid'] ?? '—'), 18) }}</dd></div>
            </dl>
            @if($application->is_self)
                <p class="text-xs text-cyan-300/80 mt-3">⚠ This is the application the control plane itself runs in — restarting it briefly takes the dashboard down with it. The restart request is accepted before that happens, and the audit + Slack records persist either way.</p>
            @endif
        @elseif($webhook)
            <div class="text-sm text-slate-100 font-medium">Webhook #{{ $webhook->id }} — {{ $webhook->message_type ?? 'unknown type' }}</div>
            <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1.5 text-xs">
                <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Invoice</dt><dd class="text-slate-300 font-mono text-xs">{{ $webhook->invoice_id ?? '—' }}</dd></div>
                <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Current status</dt><dd class="text-slate-300 font-mono text-xs">{{ $webhook->status }}</dd></div>
                <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Received</dt><dd class="text-slate-300">{{ $webhook->processed_at?->diffForHumans() ?? '—' }}</dd></div>
                <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Previous replays</dt><dd class="text-slate-300">{{ (int) ($webhook->replay_count ?? 0) }}×</dd></div>
            </dl>
        @elseif($failedJob)
            <div class="text-sm text-slate-100 font-medium font-mono text-[13px] break-all">{{ $failedJob['job'] }}</div>
            <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1.5 text-xs">
                <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Queue</dt><dd class="text-slate-300 font-mono text-xs">{{ $failedJob['queue'] }}</dd></div>
                <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Connection</dt><dd class="text-slate-300 font-mono text-xs">{{ $failedJob['connection'] }}</dd></div>
                <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">Failed</dt><dd class="text-slate-300">{{ \Illuminate\Support\Carbon::parse($failedJob['failed_at'])->diffForHumans() }}</dd></div>
                <div class="flex justify-between border-b border-slate-800/60 py-1"><dt class="text-slate-500">UUID</dt><dd class="text-slate-400 font-mono text-xs truncate" title="{{ $failedJob['uuid'] }}">{{ \Illuminate\Support\Str::limit($failedJob['uuid'], 18) }}</dd></div>
            </dl>
            @if($failedJob['first_exception'] !== '')
                <div class="mt-3 text-xs text-red-300/90 font-mono bg-red-950/20 border border-red-900/40 rounded-md px-3 py-2 break-all">{{ $failedJob['first_exception'] }}</div>
            @endif
            @if($actionId === 'queue.forget')
                <p class="text-xs text-red-300/90 mt-3 leading-relaxed">⚠ The failed-jobs row is the ONLY copy of this payload and its exception trace — deleting it is permanent, with no archive and no undo. If anything about the job might still matter, copy what you need (the queue page shows the payload excerpt) BEFORE confirming.</p>
            @endif
        @endif
    </section>

    {{-- Consequences: will / won't --}}
    <div class="grid md:grid-cols-2 gap-4 mb-6">
        <section class="rounded-lg border border-red-900/50 bg-red-950/20 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-red-400 mb-3">This WILL</h2>
            <ul class="space-y-2">
                @foreach($definition['will_do'] as $item)
                    <li class="text-xs text-slate-300 flex gap-2"><span class="text-red-400 mt-0.5">▸</span><span>{{ $item }}</span></li>
                @endforeach
            </ul>
        </section>
        <section class="rounded-lg border border-emerald-900/50 bg-emerald-950/20 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">This will NOT</h2>
            <ul class="space-y-2">
                @foreach($definition['wont_do'] as $item)
                    <li class="text-xs text-slate-300 flex gap-2"><span class="text-emerald-400 mt-0.5">▸</span><span>{{ $item }}</span></li>
                @endforeach
            </ul>
        </section>
    </div>

    <section class="rounded-lg border border-amber-900/50 bg-amber-950/20 p-5 mb-6">
        <h2 class="text-xs font-bold uppercase tracking-wider text-amber-400 mb-2">Bottom line</h2>
        <p class="text-sm text-slate-300 leading-relaxed">{{ $definition['consequence'] }}</p>
    </section>

    {{-- The confirmation form: typed phrase + password --}}
    {{-- ITERATION-3: data-busy — restart/replay/forget are slow, the Execute
         button used to stay clickable for the whole run. --}}
    <form method="POST" action="{{ route('ops.actions.execute', $actionId) }}" class="rounded-lg border border-slate-800 bg-slate-900/60 p-6 space-y-5"
          data-busy data-busy-label="Executing…">
        @csrf
        @if($application)
            <input type="hidden" name="application" value="{{ $application->id }}">
        @endif
        @if($webhook)
            <input type="hidden" name="webhook" value="{{ $webhook->id }}">
        @endif
        @if($failedJob)
            <input type="hidden" name="job" value="{{ $failedJob['uuid'] }}">
        @endif

        <div>
            <label for="confirm" class="block text-sm font-medium text-slate-200 mb-1.5">
                Type <span class="font-mono font-bold text-amber-300 px-1.5 py-0.5 rounded bg-amber-950/60 border border-amber-800/60">{{ $definition['confirmation_phrase'] }}</span> to confirm
            </label>
            <input id="confirm" name="confirm" type="text" autocomplete="off" spellcheck="false"
                   class="input-ops bg-slate-950 text-slate-100 font-mono tracking-widest focus:border-amber-500 {{ $errors->has('confirm') ? 'border-red-600' : '' }}"
                   placeholder="{{ $definition['confirmation_phrase'] }}" value="{{ old('confirm') }}">
            @error('confirm')<p class="text-xs text-red-400 mt-1.5">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-slate-200 mb-1.5">Your account password</label>
            <input id="password" name="password" type="password" autocomplete="current-password"
                   class="input-ops bg-slate-950 text-slate-100 focus:border-amber-500 {{ $errors->has('password') ? 'border-red-600' : '' }}">
            @error('password')<p class="text-xs text-red-400 mt-1.5">{{ $message }}</p>@enderror
            <p class="text-xs text-slate-500 mt-1.5">Required fresh for every elevated action — session confirmation is not carried over.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-slate-800/70">
            <button type="submit" class="btn btn-ops-danger whitespace-nowrap">
                Execute: {{ $definition['label'] }}
            </button>
            <a href="{{ route('ops.actions.index') }}" class="btn btn-ops-secondary">Cancel</a>
            <span class="text-xs text-slate-500">This will be audited and announced in Slack.</span>
        </div>
    </form>
</div>
@endsection
