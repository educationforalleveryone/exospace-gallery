<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">Cohort drill-down</h2>
    </x-slot>

<div class="max-w-7xl mx-auto px-4 sm:px-6 py-8">
    <a href="{{ route('super.index') }}" class="text-sm text-gray-400 hover:text-white mb-4 inline-block">← Back to Master Control</a>

    <h1 class="text-2xl font-bold text-white mb-2">🔁 Cohort {{ $cohort->format('M j, Y') }} — Week {{ $weekIndex }}</h1>
    <p class="text-sm text-gray-400 mb-6">
        Members who registered during the week of <strong>{{ $cohort->format('M j') }}</strong> –
        <strong>{{ $cohort->copy()->addDays(6)->format('M j, Y') }}</strong>, with their activity status
        during week {{ $weekIndex }} ({{ $periodStart->format('M j') }} – {{ $periodEnd->copy()->subDay()->format('M j, Y') }}).
        "Active in week {{ $weekIndex }}" = a login (users.last_login_at) OR a gallery update in the period.
    </p>

    {{-- ITERATION 8: CSV export — same audit-logged PII surface as the page itself.
         The audit row (retention.cohort_exported) is written BEFORE the stream
         starts, so an interrupted export is still attributable. --}}
    <div class="mb-4">
        <a href="{{ route('super.retention.cohort.export', ['cohort' => $cohort->format('Y-m-d'), 'week' => $weekIndex]) }}"
           class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-emerald-600/20 border border-emerald-500/40 text-emerald-300 text-xs font-medium hover:bg-emerald-600/30 transition"
           title="Streamed CSV of the {{ $size }} member(s) in this cohort ({{ $activeCount }} active in week {{ $weekIndex }}). Every export is audit-logged (retention.cohort_exported).">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5m0 0l5-5m-5 5V3"/></svg>
            Export CSV ({{ $size }} member{{ $size === 1 ? '' : 's' }})
        </a>
    </div>

    {{-- Cohort facts — counts are derived live from the same bounded activity
         definition as the matrix (countActive), so they reconcile with the
         cell the operator clicked. Tiny drift from the cached matrix is
         possible if users registered between cache TTL and click — documented. --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
        <div class="bg-gray-900 border border-gray-700 rounded-xl p-3 text-center">
            <div class="text-2xl font-bold text-white">{{ number_format($size) }}</div>
            <div class="text-[11px] text-gray-500 mt-0.5">Cohort size</div>
        </div>
        <div class="bg-gray-900 border border-gray-700 rounded-xl p-3 text-center">
            <div class="text-2xl font-bold text-emerald-400">{{ number_format($activeCount) }}</div>
            <div class="text-[11px] text-gray-500 mt-0.5">Active in week {{ $weekIndex }}</div>
        </div>
        <div class="bg-gray-900 border border-gray-700 rounded-xl p-3 text-center">
            <div class="text-2xl font-bold text-emerald-300">{{ $pct }}%</div>
            <div class="text-[11px] text-gray-500 mt-0.5">Retained (live)</div>
        </div>
        <div class="bg-gray-900 border border-gray-700 rounded-xl p-3 text-center">
            <div class="text-2xl font-bold text-gray-400">{{ $periodStart->format('M j') }} – {{ $periodEnd->copy()->subDay()->format('M j') }}</div>
            <div class="text-[11px] text-gray-500 mt-0.5">Period window</div>
        </div>
    </div>

    <div class="bg-amber-500/5 border border-amber-500/20 rounded-lg px-4 py-2 mb-6 text-xs text-amber-300">
        <strong>PII reveal:</strong> every view of this page is audit-logged (action <code>retention.cohort_viewed</code>) with the cohort coordinates and row count — the same attribution bar the Billing Review CSV export sits behind.
    </div>

    {{-- Members table — same shape as the dashboard users table --}}
    <div class="bg-gray-900 border border-gray-700 rounded-2xl overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 uppercase tracking-wider border-b border-gray-700">
                    <th class="px-5 py-3">User</th>
                    <th class="px-5 py-3">Plan</th>
                    <th class="px-5 py-3">Registered</th>
                    <th class="px-5 py-3">Last login</th>
                    <th class="px-5 py-3 text-center">Active in W{{ $weekIndex }}</th>
                    <th class="px-5 py-3 text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($members as $member)
                    <tr class="border-b border-gray-800 last:border-0 hover:bg-gray-800/30 transition">
                        <td class="px-5 py-3">
                            <div class="font-medium text-white">{{ $member->name }}</div>
                            <div class="text-xs text-gray-500">{{ $member->email }}</div>
                        </td>
                        <td class="px-5 py-3 capitalize text-gray-300">{{ $member->plan }}</td>
                        <td class="px-5 py-3 text-gray-400 text-xs">{{ $member->created_at?->format('M j, Y') }}</td>
                        <td class="px-5 py-3 text-gray-400 text-xs">{{ $member->last_login_at?->diffForHumans() ?? '—' }}</td>
                        <td class="px-5 py-3 text-center">
                            @if((int) ($member->active_in_period ?? 0) === 1)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-900/40 text-emerald-300 border border-emerald-700/30">active</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-gray-800 text-gray-500 border border-gray-700/30">inactive</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-center">
                            @if($member->banned_at)
                                <span class="text-red-400 text-xs">banned</span>
                            @elseif($member->is_super_admin)
                                <span class="text-indigo-400 text-xs">super</span>
                            @else
                                <span class="text-gray-600 text-xs">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-12 text-center text-gray-500">
                            No members registered during this cohort's week.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($members->hasPages())
        <div class="mt-4">{{ $members->links() }}</div>
    @endif
</div>
</x-app-layout>
