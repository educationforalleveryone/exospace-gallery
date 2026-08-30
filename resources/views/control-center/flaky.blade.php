@extends('control-center.layout')

@section('title', 'Flaky Board')

@section('content')
<h1 class="mb-2 text-2xl font-bold tracking-tight">Reliability Board</h1>
<p class="mb-6 text-sm text-slate-500">
    Flaky tests are <b class="text-purple-400">not hidden</b> — they alternate pass/fail and will bite release day.
    Perma-red entries are listed too, so nothing vanishes silently.
</p>

<form method="GET" class="mb-4 text-sm">
    <select name="profile" class="input-ops focus:border-brand-500">
        <option value="">All profiles</option>
        @foreach ($profiles as $key => $label)
            <option value="{{ $key }}" @selected(request('profile') === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <button class="btn btn-ops-secondary ml-2">Filter</button>
</form>

<div class="overflow-x-auto rounded-xl border border-slate-800">
    <table class="min-w-full divide-y divide-slate-800 text-sm">
        <thead class="bg-slate-900/70 text-left text-xs uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-4 py-3">Kind</th><th class="px-4 py-3">Test</th><th class="px-4 py-3">Profile</th>
                <th class="px-4 py-3">Executions</th><th class="px-4 py-3">Pass rate</th>
                <th class="px-4 py-3">Last</th><th class="px-4 py-3">Last failure note</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-900">
            @forelse ($tests as $t)
                @php
                    $kindStyle = match ($t['kind']) {
                        'flaky'    => 'border-purple-700 text-purple-300',
                        'perma-red'=> 'border-red-800 text-red-300',
                        default    => 'border-orange-700 text-orange-300',
                    };
                @endphp
                <tr class="hover:bg-slate-900/40">
                    <td class="px-4 py-3"><x-status-badge :state="$t['kind'] === 'flaky' ? 'warning' : ($t['kind'] === 'perma_fail' ? 'critical' : 'warning')" :label="$t['kind']" /></td>
                    <td class="px-4 py-3"><code class="text-xs">{{ $t['test_identifier'] }}</code></td>
                    <td class="px-4 py-3 text-xs">{{ $t['profile'] }}</td>
                    <td class="px-4 py-3">{{ $t['executions'] }}</td>
                    <td class="px-4 py-3 font-semibold {{ $t['pass_rate'] >= 90 ? 'text-emerald-400' : ($t['pass_rate'] >= 50 ? 'text-amber-400' : 'text-red-400') }}">{{ $t['pass_rate'] }}%</td>
                    <td class="px-4 py-3 uppercase text-xs {{ $t['last_status']==='pass' ? 'text-emerald-500' : 'text-red-400' }}">{{ $t['last_status'] }}</td>
                    <td class="max-w-md truncate px-4 py-3 text-xs text-slate-500" title="{{ $t['last_problem_message'] }}">{{ Str::limit((string) $t['last_problem_message'], 120) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No reliability suspects in the current window. 🎉</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
