@extends('control-center.layout')

@section('title', 'Runs')

@section('content')
<h1 class="mb-6 text-2xl font-bold tracking-tight">Run History</h1>

<form class="mb-4 flex flex-wrap gap-3 text-sm" method="GET">
    <select name="profile" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2">
        <option value="">All profiles</option>
        @foreach ($profiles as $key => $label)
            <option value="{{ $key }}" @selected(request('profile') === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="status" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2">
        <option value="">Any status</option>
        @foreach (['passed','failed','running','queued','blocked','not_executed','cancelled','timed_out'] as $s)
            <option value="{{ $s }}" @selected(request('status') === $s)>{{ strtoupper($s) }}</option>
        @endforeach
    </select>
    <button class="rounded-md bg-slate-800 px-4 py-2 hover:bg-slate-700">Filter</button>
</form>

<div class="overflow-x-auto rounded-xl border border-slate-800">
    <table class="min-w-full divide-y divide-slate-800 text-sm">
        <thead class="bg-slate-900/70 text-left text-xs uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-4 py-3">#</th><th class="px-4 py-3">Profile</th><th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Tests</th><th class="px-4 py-3">Pass / Fail / Skip</th>
                <th class="px-4 py-3">Env · Trigger</th><th class="px-4 py-3">Commit</th>
                <th class="px-4 py-3">When</th><th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-900">
            @forelse ($runs as $r)
                @php
                    $color = match ($r->status) {
                        'passed' => 'text-emerald-400', 'failed' => 'text-red-400',
                        'timed_out' => 'text-orange-400', default => 'text-slate-500',
                    };
                @endphp
                <tr class="hover:bg-slate-900/40">
                    <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $r->id }}</td>
                    <td class="px-4 py-3 font-medium">{{ $r->profile }}</td>
                    <td class="px-4 py-3 font-semibold {{ $color }}">{{ strtoupper($r->displayStatus()) }}</td>
                    <td class="px-4 py-3">{{ number_format($r->total) }}</td>
                    <td class="px-4 py-3 font-mono text-xs">
                        <span class="text-emerald-400">{{ $r->passed }}</span>/<span class="text-red-400">{{ $r->failed + $r->errored }}</span>/<span>{{ $r->skipped }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-400">{{ $r->environment }} · {{ $r->trigger }}</td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ Str::limit($r->git_commit ?? '—', 7, '') }}</td>
                    <td class="px-4 py-3 text-xs text-slate-400" title="{{ $r->created_at }}">{{ $r->created_at->diffForHumans() }}</td>
                    <td class="px-4 py-3"><a class="text-sky-400 hover:text-sky-300" href="{{ route('control-center.run.show', $r) }}">detail →</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-10 text-center text-slate-500">No runs match. Trigger one from the Overview wall.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $runs->links() }}</div>
@endsection
