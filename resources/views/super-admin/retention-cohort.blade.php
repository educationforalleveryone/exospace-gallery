<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'Cohort '.$cohort->format('M j, Y').' — Week '.$weekIndex" :back="route('super.index')" backLabel="Master Control"/>
    </x-slot>

    <div class="page-shell">
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
            <div class="text-xs text-gray-500 mt-0.5">Cohort size</div>
        </div>
        <div class="bg-gray-900 border border-gray-700 rounded-xl p-3 text-center">
            <div class="text-2xl font-bold text-emerald-400">{{ number_format($activeCount) }}</div>
            <div class="text-xs text-gray-500 mt-0.5">Active in week {{ $weekIndex }}</div>
        </div>
        <div class="bg-gray-900 border border-gray-700 rounded-xl p-3 text-center">
            <div class="text-2xl font-bold text-emerald-300">{{ $pct }}%</div>
            <div class="text-xs text-gray-500 mt-0.5">Retained (live)</div>
        </div>
        <div class="bg-gray-900 border border-gray-700 rounded-xl p-3 text-center">
            <div class="text-2xl font-bold text-gray-400">{{ $periodStart->format('M j') }} – {{ $periodEnd->copy()->subDay()->format('M j') }}</div>
            <div class="text-xs text-gray-500 mt-0.5">Period window</div>
        </div>
    </div>

    {{-- ITERATION 9 — per-cohort retention curve W0..W7. The Master Control
         matrix trends only W1 + W2 (the headline metric); this chart closes
         the "which week did churn happen?" loop by showing the cohort's
         retention curve across all 8 weeks on one canvas. W{weekIndex} is
         highlighted so the page the operator landed on is visually anchored
         (the cell they clicked in the matrix). Data: cohortCurve() reads
         the latest complete snapshot per week_index from retention_snapshots
         (the same table the weekly exospace:cohort-retention command writes).
         Partial weeks (still-running follow-up weeks) render dimmed — same
         convention as the matrix cells. The chart is hidden entirely when
         the curve is empty (size-0 cohort or no snapshots persisted yet). --}}
    @if(!empty($curve) && collect($curve)->pluck('retained_pct')->filter()->isNotEmpty())
        @php
            $curvePoints = collect($curve)->filter(fn ($c) => $c['retained_pct'] !== null)->count();
            $curveHighlight = $weekIndex;
            $curveAria = "Per-cohort retention curve, {$curvePoints} week" . ($curvePoints === 1 ? '' : 's') . " recorded. Week {$weekIndex} highlighted.";
        @endphp
        <div class="bg-gray-900/50 border border-gray-700/30 rounded-lg p-4 mb-6">
            <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
                <h2 class="eyebrow">📈 Cohort retention curve — W0 to W7</h2>
                <div class="text-xs text-gray-600">{{ $curvePoints }} point{{ $curvePoints === 1 ? '' : 's' }} recorded · week {{ $weekIndex }} highlighted</div>
            </div>
            <div class="h-48"><canvas id="cohort-curve-chart" role="img" aria-label="{{ $curveAria }}"></canvas></div>
            <div class="text-xs text-gray-600 mt-1">% of this cohort active (login or gallery update) in each of their first 8 weeks after registration. Higher is better. The highlighted column is the week you clicked through from the matrix.</div>
        </div>
    @endif

    <div class="bg-amber-500/5 border border-amber-500/20 rounded-lg px-4 py-2 mb-6 text-xs text-amber-300">
        <strong>PII reveal:</strong> every view of this page is audit-logged (action <code>retention.cohort_viewed</code>) with the cohort coordinates and row count — the same attribution bar the Billing Review CSV export sits behind.
    </div>

    {{-- Members table — same shape as the dashboard users table --}}
    <div class="table-wrap">
            <table class="table-base min-w-[760px]">
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
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-900/40 text-emerald-300 border border-emerald-700/30">active</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-800 text-gray-500 border border-gray-700/30">inactive</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-center">
                            @if($member->banned_at)
                                <span class="text-red-400 text-xs">banned</span>
                            @elseif($member->is_super_admin)
                                <span class="text-brand-400 text-xs">super</span>
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

{{-- ITERATION 9 — per-cohort retention curve chart. Same waitForChart
     pattern as the Master Control TTFE + retention charts (poll for
     window.Chart loaded by admin-vendor.js). The highlight plugin rings
     the W{weekIndex} point so the page the operator landed on is
     visually anchored (the cell they clicked in the matrix). Partial
     weeks render as dimmed points (rgba(156,163,175,0.4)) — same
     convention as the matrix cells (italic + opacity-50). --}}
@if(!empty($curve) && collect($curve)->pluck('retained_pct')->filter()->isNotEmpty())
    <script nonce="@nonce">
    (function () {
        var canvas = document.getElementById('cohort-curve-chart');
        if (!canvas) return;

        var curve = @json($curve);
        var highlight = {{ (int) $weekIndex }};

        // Pluck W0..W7 labels + retention % per row. Partial weeks
        // (still-running follow-up weeks where complete=false) get a
        // dimmer point color so the operator can see how much of the
        // curve is final vs provisional — same convention as the matrix.
        var labels = curve.map(function (c) { return 'W' + c.week_index; });
        var data = curve.map(function (c) { return c.retained_pct; });
        var pointColors = curve.map(function (c) {
            if (c.week_index === highlight) return 'rgba(244,114,182,1)'; // rose-400 highlight
            return c.complete ? 'rgba(52,211,153,1)' : 'rgba(156,163,175,0.4)'; // emerald complete / dim partial
        });
        var pointRadii = curve.map(function (c) { return c.week_index === highlight ? 6 : 3; });

        function waitForChart(attemptsLeft) {
            if (window.Chart) { initCurveChart(); return; }
            if (attemptsLeft <= 0) {
                console.error('Chart.js failed to load (admin-vendor.js) — cohort curve not rendered.');
                return;
            }
            setTimeout(function () { waitForChart(attemptsLeft - 1); }, 100);
        }

        function initCurveChart() {
            new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Retention (%)',
                        data: data,
                        borderColor: '#34d399',
                        backgroundColor: 'rgba(52,211,153,0.08)',
                        borderWidth: 2,
                        pointRadius: pointRadii,
                        pointBackgroundColor: pointColors,
                        tension: 0.3,
                        spanGaps: true,
                        fill: true,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: '#9ca3af', font: { size: 11 } } } },
                    scales: {
                        x: {
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            ticks: { color: '#6b7280', font: { size: 10 } }
                        },
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            ticks: { color: '#6b7280', font: { size: 10 }, callback: function (v) { return v + '%'; } },
                            title: { display: true, text: '% of cohort active', color: '#6b7280', font: { size: 10 } }
                        }
                    }
                }
            });
        }

        waitForChart(30);
    })();
    </script>
@endif
</x-app-layout>
