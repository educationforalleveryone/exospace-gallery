@extends('ops.layout')

@section('title', 'Credentials')

@section('content')
<div class="mb-4">
    <h1 class="text-xl font-semibold">Credentials — rotation governance</h1>
    <p class="text-xs text-slate-400 mt-1">
        The §15 checklist of the master manual, made live: what credential surfaces exist, which are configured,
        when each was last rotated, and what to do next. <strong class="text-slate-300">Values are never stored,
        never read past a yes/no, and never displayed</strong> — this page tracks the ROTATION, not the secret.
    </p>
</div>

{{-- ── Summary + §15 banner ───────────────────────────────────────────── --}}
<div class="grid sm:grid-cols-4 gap-3 mb-6">
    <div class="rounded-lg border {{ $counts['rotate_now'] > 0 ? 'border-red-700/60 bg-red-950/30' : 'border-slate-800 bg-slate-900/40' }} px-4 py-3">
        <div class="text-2xl font-bold {{ $counts['rotate_now'] > 0 ? 'text-red-300' : 'text-slate-300' }}">{{ $counts['rotate_now'] }}</div>
        <div class="text-xs text-slate-400 uppercase tracking-wider mt-0.5">Rotate now</div>
    </div>
    <div class="rounded-lg border {{ $counts['overdue'] > 0 ? 'border-orange-700/60 bg-orange-950/30' : 'border-slate-800 bg-slate-900/40' }} px-4 py-3">
        <div class="text-2xl font-bold {{ $counts['overdue'] > 0 ? 'text-orange-300' : 'text-slate-300' }}">{{ $counts['overdue'] }}</div>
        <div class="text-xs text-slate-400 uppercase tracking-wider mt-0.5">Overdue</div>
    </div>
    <div class="rounded-lg border border-slate-800 bg-slate-900/40 px-4 py-3">
        <div class="text-2xl font-bold text-amber-300">{{ $counts['due_soon'] }}</div>
        <div class="text-xs text-slate-400 uppercase tracking-wider mt-0.5">Due soon</div>
    </div>
    <div class="rounded-lg border border-slate-800 bg-slate-900/40 px-4 py-3">
        <div class="text-2xl font-bold text-emerald-300">{{ $counts['ok'] }}</div>
        <div class="text-xs text-slate-400 uppercase tracking-wider mt-0.5">Within cadence</div>
    </div>
</div>

@if($counts['rotate_now'] > 0)
<div class="mb-6 rounded-lg border border-red-700/60 bg-red-950/30 px-4 py-3 text-sm text-red-200">
    <strong>{{ $counts['rotate_now'] }} credential(s) were exposed at project kickoff and have no recorded rotation.</strong>
    Rotate them in the provider's dashboard (guidance per row below, full detail in the master manual §15),
    then record the rotation here — the row turns green and the audit trail + Slack channel get the entry.
</div>
@endif

{{-- ── Inventory table ─────────────────────────────────────────────────── --}}
<div class="overflow-x-auto rounded-lg border border-slate-800">
    <table class="w-full text-sm">
        <thead class="bg-slate-900/80 text-slate-400 text-xs uppercase tracking-wider">
            <tr>
                <th class="text-left px-4 py-3">Credential</th>
                <th class="text-left px-4 py-3">Env vars</th>
                <th class="text-left px-4 py-3">Configured</th>
                <th class="text-left px-4 py-3">Last rotated</th>
                <th class="text-left px-4 py-3">Status</th>
                <th class="text-right px-4 py-3">Record rotation</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/80">
            @foreach($items as $item)
                @php
                    $statusStyles = [
                        'rotate_now' => 'bg-red-950/60 text-red-300 border-red-700/60',
                        'overdue'    => 'bg-orange-950/60 text-orange-300 border-orange-700/60',
                        'due_soon'   => 'bg-amber-950/60 text-amber-300 border-amber-700/60',
                        'ok'         => 'bg-emerald-950/60 text-emerald-300 border-emerald-700/60',
                        'untracked'  => 'bg-slate-800/60 text-slate-400 border-slate-600/50',
                    ];
                    $statusLabels = [
                        'rotate_now' => 'ROTATE NOW',
                        'overdue'    => 'OVERDUE',
                        'due_soon'   => 'DUE SOON',
                        'ok'         => 'OK',
                        'untracked'  => 'UNTRACKED',
                    ];
                    $tooltip = $item['guidance']
                        .($item['cadence'] !== null ? " · Recommended cadence: every {$item['cadence']} days." : ' · No fixed cadence: rotate on policy.')
                        .($item['exposed'] ? ' · Exposed at project kickoff (§15).' : '');
                @endphp
                <tr class="hover:bg-slate-900/60 align-top">
                    <td class="px-4 py-3">
                        <div class="text-slate-100 font-medium">{{ $item['name'] }}</div>
                        <div class="text-xs text-slate-500">{{ $item['category'] }}</div>
                        @if($item['notes'])
                            <div class="text-xs text-slate-400 mt-1 italic">“{{ $item['notes'] }}”</div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @foreach($item['env'] as $var)
                            <div class="text-xs font-mono text-slate-400">{{ $var }}</div>
                        @endforeach
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-xs px-2 py-1 rounded border {{ $item['configured'] ? 'bg-emerald-950/60 text-emerald-300 border-emerald-700/50' : 'bg-slate-800/60 text-slate-400 border-slate-600/50' }} font-semibold">
                            {{ $item['configured'] ? 'YES' : 'NOT SET' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-300 text-xs">
                        @if($item['last_rotated_at'])
                            {{ $item['last_rotated_at']->diffForHumans() }}<br>
                            <span class="text-slate-500">{{ $item['days_since'] }} day(s) ago{{ $item['rotated_by'] ? ' · by '.$item['rotated_by'] : '' }}</span>
                        @else
                            <span class="text-slate-500">never recorded</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <x-status-badge :state="match($item['status']) { 'rotate_now', 'overdue' => 'critical', 'due_soon' => 'warning', 'ok' => 'healthy', default => 'unknown' }" :label="$statusLabels[$item['status']]" :title="$tooltip" />
                    </td>
                    <td class="px-4 py-3 text-right">
                        <form method="POST" action="{{ route('ops.credentials.rotate', $item['key']) }}" class="inline-flex flex-col gap-1.5 items-end"
                              data-busy data-busy-label="Recording…">
                            @csrf
                            <input type="text" name="note" maxlength="250" placeholder="optional note (never a value!)" class="input-ops-sm w-52 focus:border-sky-600">
                            <button class="btn btn-sm btn-ops-sky-ghost" title="{{ $tooltip }}">I rotated this ✓</button>
                        </form>
                    </td>
                </tr>
            @empty
                {{-- ITERATION-4: defensive empty branch. The inventory is
                    config-driven (OpsCredentialInventoryService) so this is
                    not expected to fire, but a blank tbody under a full
                    header row would read as a broken page if the catalog
                    ever returns empty. --}}
                <tr>
                    <td colspan="5" class="px-4 py-6 text-center text-xs text-slate-400">
                        No credentials are being tracked. Check the credential inventory catalog configuration.
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="mt-4 text-xs text-slate-600 space-y-1">
    <p>How to use: rotate in the provider's dashboard first (guidance on each row; full walkthrough in the master manual §13 + §15), then record it here. Recording writes the ledger row, an <span class="font-mono">ops.credential.rotated</span> audit entry and a Slack note — it changes nothing else.</p>
    <p>“UNTRACKED” = the credential is optional and has no recorded rotation: recording one starts the cadence clock. Cadences: 90 days for API keys/tokens, 180 days for webhooks and secret words; APP_KEY is policy-driven (rotation logs every user out once — maintenance window only).</p>
    <p>Configured = the env var is present and non-empty (a yes/no read from cached config). The probe cannot return, and the ledger cannot store, a secret value.</p>
</div>
@endsection
