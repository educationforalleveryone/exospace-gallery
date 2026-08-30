@extends('ops.layout')

@section('title', 'Errors & Events')

@section('content')
<div class="mb-4">
    <h1 class="text-xl font-semibold">Errors &amp; Events</h1>
    <p class="text-xs text-slate-400 mt-1">Normalized, deduplicated events from every source — application logs, exceptions, the Coolify API, and self-reporting applications.</p>
</div>

{{-- ── Filters ───────────────────────────────────────────────────────── --}}
<form method="GET" action="{{ route('ops.events') }}" class="rounded-lg border border-slate-800 bg-slate-900/40 p-4 mb-4">
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 text-sm">
        <div class="col-span-2">
            <label class="block text-xs uppercase tracking-wider text-slate-500 mb-1">Search</label>
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="title or message…"
                   class="w-full bg-slate-950 border border-slate-700 rounded px-2.5 py-1.5 text-slate-200 placeholder-slate-600 focus:border-emerald-600 focus:outline-none">
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wider text-slate-500 mb-1">Severity</label>
            <select name="severity" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1.5 text-slate-200 focus:border-emerald-600 focus:outline-none">
                <option value="">All</option>
                @foreach(\App\Ops\Models\OpsEvent::SEVERITIES as $sev)
                    <option value="{{ $sev }}" {{ $filters['severity'] === $sev ? 'selected' : '' }}>{{ ucfirst($sev) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wider text-slate-500 mb-1">Category</label>
            <select name="category" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1.5 text-slate-200 focus:border-emerald-600 focus:outline-none">
                <option value="">All</option>
                @foreach(\App\Ops\Models\OpsEvent::CATEGORIES as $cat)
                    <option value="{{ $cat }}" {{ $filters['category'] === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wider text-slate-500 mb-1">Application</label>
            <select name="application" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1.5 text-slate-200 focus:border-emerald-600 focus:outline-none">
                <option value="">All</option>
                @foreach($applications as $app)
                    <option value="{{ $app->id }}" {{ $filters['application'] == $app->id ? 'selected' : '' }}>{{ $app->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wider text-slate-500 mb-1">Window</label>
            <select name="hours" class="w-full bg-slate-950 border border-slate-700 rounded px-2 py-1.5 text-slate-200 focus:border-emerald-600 focus:outline-none">
                @foreach([24 => '24 hours', 168 => '7 days', 720 => '30 days', 0 => 'All time'] as $h => $label)
                    <option value="{{ $h }}" {{ (int)$filters['hours'] === $h ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="flex items-center gap-3 mt-3">
        <button class="px-4 py-1.5 bg-emerald-700 hover:bg-emerald-600 rounded text-sm font-medium">Apply filters</button>
        <select name="status" class="bg-slate-950 border border-slate-700 rounded px-2 py-1.5 text-slate-300 text-sm focus:border-emerald-600 focus:outline-none">
            <option value="active" {{ $filters['status'] === 'active' ? 'selected' : '' }}>Active (open + acknowledged)</option>
            <option value="open" {{ $filters['status'] === 'open' ? 'selected' : '' }}>Open</option>
            <option value="resolved" {{ $filters['status'] === 'resolved' ? 'selected' : '' }}>Resolved</option>
            <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>All statuses</option>
        </select>
        <a href="{{ route('ops.events') }}" class="text-xs text-slate-500 hover:text-slate-300">Reset</a>
    </div>
</form>

{{-- ── Events table ──────────────────────────────────────────────────── --}}
<div class="overflow-x-auto rounded-lg border border-slate-800">
    <table class="w-full text-sm">
        <thead class="bg-slate-900/80 text-slate-400 text-xs uppercase tracking-wider">
            <tr>
                <th class="text-left px-4 py-3">Severity</th>
                <th class="text-left px-4 py-3">What</th>
                <th class="text-left px-4 py-3">Where</th>
                <th class="text-left px-4 py-3">Category</th>
                <th class="text-left px-4 py-3">Occurrences</th>
                <th class="text-left px-4 py-3">First / Last Seen</th>
                <th class="text-left px-4 py-3">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/80">
            @forelse($events as $event)
                @php
                    $sev = [
                        'critical' => ['text-red-300','bg-red-950/60','border-red-800/60'],
                        'error'    => ['text-orange-300','bg-orange-950/50','border-orange-800/50'],
                        'warning'  => ['text-amber-300','bg-amber-950/50','border-amber-800/50'],
                        'info'     => ['text-slate-300','bg-slate-800/60','border-slate-700/50'],
                    ][$event->severity] ?? ['text-slate-300','bg-slate-800/60','border-slate-700/50'];
                @endphp
                <tr class="hover:bg-slate-900/60 cursor-pointer" data-href="{{ route('ops.events.show', $event) }}" tabindex="0" aria-label="Open event: {{ $event->title ?? $event->message }}">
                    <td class="px-4 py-3">
                        <span class="text-xs font-bold px-2 py-1 rounded border {{ $sev[1] }} {{ $sev[2] }} {{ $sev[0] }}">{{ strtoupper($event->severity) }}</span>
                    </td>
                    <td class="px-4 py-3 max-w-md">
                        <div class="text-slate-100 font-medium truncate">{{ $event->title }}</div>
                        @if($event->message)<div class="text-xs text-slate-500 truncate">{{ \Illuminate\Support\Str::limit($event->message, 90) }}</div>@endif
                    </td>
                    <td class="px-4 py-3 text-slate-300">{{ $event->application?->name ?? '—' }}</td>
                    <td class="px-4 py-3"><span class="text-xs font-mono text-slate-400">{{ $event->category }}</span></td>
                    <td class="px-4 py-3">
                        <span class="text-slate-200 font-semibold">{{ $event->occurrence_count }}</span>
                        <span class="text-xs text-slate-600">/ {{ $event->total_count }} total</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-400">
                        <div>first {{ $event->first_seen_at?->diffForHumans() }}</div>
                        <div>last {{ $event->last_seen_at?->diffForHumans() }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <x-status-badge :state="$event->status === 'resolved' ? 'healthy' : ($event->status === 'open' ? 'warning' : 'info')" :label="$event->status" />
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-slate-500 text-sm">No events match the current filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $events->links() }}</div>
@endsection
