@extends('ops.layout')

@section('title', 'Incidents')

@section('content')
<div class="mb-4">
    <h1 class="text-xl font-semibold">Incidents</h1>
    <p class="text-xs text-slate-400 mt-1">Correlated event chains — one operational story, not five unrelated errors. Root causes are ranked candidates, never certainties.</p>
</div>

<div class="flex items-center gap-2 mb-4 text-sm">
    @foreach(['active' => 'Active', 'open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved'] as $value => $label)
        <a href="{{ route('ops.incidents.index', ['status' => $value]) }}"
           class="px-3 py-1.5 rounded-md {{ $status === $value ? 'bg-emerald-600/20 text-emerald-300 border border-emerald-700/40' : 'text-slate-400 hover:bg-slate-800' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="overflow-x-auto rounded-lg border border-slate-800">
    <table class="w-full text-sm">
        <thead class="bg-slate-900/80 text-slate-400 text-[11px] uppercase tracking-wider">
            <tr>
                <th class="text-left px-4 py-3">Severity</th>
                <th class="text-left px-4 py-3">Incident</th>
                <th class="text-left px-4 py-3">Application</th>
                <th class="text-left px-4 py-3">Root cause candidate</th>
                <th class="text-left px-4 py-3">Events</th>
                <th class="text-left px-4 py-3">Started / Last activity</th>
                <th class="text-left px-4 py-3">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/80">
            @forelse($incidents as $incident)
                @php
                    $sev = [
                        'critical' => ['text-red-300','bg-red-950/60','border-red-800/60'],
                        'error'    => ['text-orange-300','bg-orange-950/50','border-orange-800/50'],
                        'warning'  => ['text-amber-300','bg-amber-950/50','border-amber-800/50'],
                        'info'     => ['text-slate-300','bg-slate-800/60','border-slate-700/50'],
                    ][$incident->severity] ?? ['text-slate-300','bg-slate-800/60','border-slate-700/50'];
                    $conf = ['high' => 'text-emerald-400', 'medium' => 'text-amber-400', 'low' => 'text-slate-500'][$incident->confidence] ?? 'text-slate-500';
                @endphp
                <tr class="hover:bg-slate-900/60 cursor-pointer" onclick="window.location='{{ route('ops.incidents.show', $incident) }}'">
                    <td class="px-4 py-3">
                        <span class="text-[9px] font-bold px-2 py-1 rounded border {{ $sev[1] }} {{ $sev[2] }} {{ $sev[0] }}">{{ strtoupper($incident->severity) }}</span>
                    </td>
                    <td class="px-4 py-3 max-w-sm">
                        <div class="text-slate-100 font-medium truncate">{{ $incident->title }}</div>
                        @if(data_get($incident->context, 'commit'))
                            <div class="text-[10px] text-slate-500 font-mono">commit {{ \Illuminate\Support\Str::limit(data_get($incident->context, 'commit'), 8, '') }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-300">{{ $incident->application?->name ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <div class="text-xs text-slate-300">{{ $incident->rootCause?->title ?? '—' }}</div>
                        <div class="text-[10px] {{ $conf }}">confidence: {{ $incident->confidence }}</div>
                    </td>
                    <td class="px-4 py-3 text-slate-200 font-semibold">{{ $incident->event_count }}</td>
                    <td class="px-4 py-3 text-xs text-slate-400">
                        <div>started {{ $incident->first_event_at?->diffForHumans() }}</div>
                        <div>last {{ $incident->last_event_at?->diffForHumans() }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-[10px] px-2 py-1 rounded {{ $incident->status === 'open' ? 'bg-slate-800 text-slate-300' : ($incident->status === 'resolved' ? 'bg-slate-900 text-slate-500 border border-slate-800' : 'bg-indigo-950/60 text-indigo-300 border border-indigo-800/50') }}">{{ $incident->status }}</span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-slate-500 text-sm">
                        No {{ $status === 'active' ? 'active' : $status }} incidents. Correlation runs every 5 minutes — related errors become one story automatically.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $incidents->links() }}</div>
@endsection
