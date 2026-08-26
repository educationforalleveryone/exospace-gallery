<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>🎯 Master Control - ExoSpace</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- ITERATION 5: Chart.js for the TTFE trend (same admin-vendor bundle
         the gallery analytics page uses; window.Chart global). --}}
    @vite(['resources/js/admin-vendor.js'])
</head>
<body class="bg-gradient-to-br from-gray-900 via-black to-gray-900 text-white min-h-screen">

    <!-- Header -->
    <div class="bg-black/50 backdrop-blur-md border-b border-red-500/30">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold bg-gradient-to-r from-red-500 to-orange-500 bg-clip-text text-transparent">
                        🎯 MASTER CONTROL
                    </h1>
                    <p class="text-gray-400 text-sm">God Mode • Super Admin Dashboard</p>
                </div>
                <div class="flex gap-4 items-center">
                    <a href="{{ route('admin.dashboard') }}" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg transition text-sm">
                        ← Dashboard
                    </a>
                    <a href="{{ route('super.venues.index') }}" class="px-4 py-2 bg-purple-800 hover:bg-purple-700 rounded-lg transition text-sm">
                        🏛️ Venue Templates
                    </a>
                    <a href="{{ route('super.featured.index') }}" class="px-4 py-2 bg-amber-700 hover:bg-amber-600 rounded-lg transition text-sm">
                        ⭐ Featured
                    </a>
                    <a href="{{ route('super.seo.index') }}" class="px-4 py-2 bg-indigo-800 hover:bg-indigo-700 rounded-lg transition text-sm">
                        🔍 SEO Operations
                    </a>
                    <a href="{{ route('super.pending-upgrades.index') }}" class="px-4 py-2 bg-blue-800 hover:bg-blue-700 rounded-lg transition text-sm">
                        💳 Pending Upgrades
                    </a>
                    <a href="{{ route('super.billing.index') }}" class="px-4 py-2 bg-red-800 hover:bg-red-700 rounded-lg transition text-sm">
                        🧾 Billing Review
                    </a>
                    <a href="{{ route('super.webhooks.index') }}" class="px-4 py-2 bg-cyan-800 hover:bg-cyan-700 rounded-lg transition text-sm">
                        📡 Outbound Webhooks
                    </a>
                    <a href="{{ route('super.feedback.index') }}" class="px-4 py-2 bg-teal-800 hover:bg-teal-700 rounded-lg transition text-sm">
                        💬 Feedback
                    </a>
                    <a href="{{ route('super.nps.index') }}" class="px-4 py-2 bg-pink-800 hover:bg-pink-700 rounded-lg transition text-sm">
                        📊 NPS
                    </a>
                    <a href="{{ route('super.affiliates.index') }}" class="px-4 py-2 bg-green-800 hover:bg-green-700 rounded-lg transition text-sm">
                        🤝 Affiliates
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg transition text-sm">Logout</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <div class="max-w-7xl mx-auto px-6 mt-4 space-y-2">
        @if(session('success'))
            <div class="bg-green-500/20 border border-green-500 text-green-300 px-4 py-3 rounded-lg flex items-center gap-2">
                ✅ {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="bg-red-500/20 border border-red-500 text-red-300 px-4 py-3 rounded-lg flex items-center gap-2">
                ❌ {{ session('error') }}
            </div>
        @endif
    </div>

    <!-- Platform Statistics -->
    <div class="max-w-7xl mx-auto px-6 py-8">
        <h2 class="text-xl font-bold mb-4 text-gray-300 uppercase tracking-wider text-sm">📊 Platform Statistics</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-9 gap-3 mb-10">
            @foreach([
                ['val' => $stats['total_users'],     'label' => 'Total Users',    'color' => 'blue'],
                ['val' => $stats['total_galleries'],  'label' => 'Galleries',      'color' => 'purple'],
                ['val' => $stats['total_images'],     'label' => 'Images',         'color' => 'indigo'],
                ['val' => number_format($stats['total_views']), 'label' => 'Views', 'color' => 'pink'],
                ['val' => $stats['free_users'],       'label' => 'Free',           'color' => 'gray'],
                ['val' => $stats['pro_users'],        'label' => 'Pro',            'color' => 'yellow'],
                ['val' => $stats['studio_users'],     'label' => 'Studio',         'color' => 'purple'],
                ['val' => $stats['banned_users'],     'label' => 'Banned',         'color' => 'red'],
                ['val' => $stats['unverified_users'], 'label' => 'Unverified',     'color' => 'orange'],
            ] as $stat)
            <div class="bg-{{ $stat['color'] }}-900/30 border border-{{ $stat['color'] }}-700/30 rounded-lg p-3 text-center">
                <div class="text-2xl font-bold text-{{ $stat['color'] }}-300">{{ $stat['val'] }}</div>
                <div class="text-xs text-gray-400 mt-0.5">{{ $stat['label'] }}</div>
            </div>
            @endforeach
        </div>

        {{-- M-14: Feature Flags status panel --}}
        <div class="mb-8 bg-gray-900/50 border border-gray-700/30 rounded-lg p-4">
            <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-3">🚩 Feature Flags</h3>
            <div class="flex flex-wrap gap-2">
                @php $flags = \App\Services\FeatureFlag::all(); @endphp
                @foreach($flags as $name => $enabled)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium
                                 {{ $enabled ? 'bg-emerald-900/40 text-emerald-300 border border-emerald-700/30' : 'bg-gray-800 text-gray-500 border border-gray-700' }}">
                        @if($enabled)
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                        @else
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                        @endif
                        {{ $name }}
                    </span>
                @endforeach
            </div>
        </div>

        {{-- ITERATION 8: Backup health tile — surfaces the worst of the
             three backup heartbeat statuses (db / files / clean) at-a-glance.
             Same data JobHeartbeatService already tracks (no new queries).
             Hidden on a fresh install with no stamps AND no acks (the
             monitor's missing-job grace window hasn't started yet — same
             convention as OperationalAlertService::checkJobHeartbeats). --}}
        @if(($backupHealth['show'] ?? false) && !empty($backupHealth['types']))
            @php
                $backupColors = [
                    'fresh'   => ['border' => 'border-emerald-500/40', 'bg' => 'bg-emerald-900/30', 'text' => 'text-emerald-300', 'icon' => '✅', 'label' => 'all fresh'],
                    'stale'   => ['border' => 'border-amber-500/40',   'bg' => 'bg-amber-900/30',   'text' => 'text-amber-300',   'icon' => '⚠️', 'label' => 'one stale'],
                    'missing' => ['border' => 'border-red-500/40',      'bg' => 'bg-red-900/30',      'text' => 'text-red-300',      'icon' => '🚨', 'label' => 'one missing'],
                ];
                $bh = $backupHealth['worst'];
                $bc = $backupColors[$bh];
            @endphp
            <div class="mb-8 {{ $bc['bg'] }} border {{ $bc['border'] }} rounded-lg p-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">{{ $bc['icon'] }}</span>
                        <div>
                            <h3 class="text-sm font-semibold {{ $bc['text'] }} uppercase tracking-wider">🗄️ Backup health — {{ $bc['label'] }}</h3>
                            <p class="text-[11px] text-gray-500 mt-0.5">Heartbeats stamped by the <code>exospace:backup</code> wrapper (Iteration 7). Per-type status below; Slack alerts fire on failure (critical for db/files, warning for clean).</p>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-3">
                    @foreach($backupHealth['types'] as $key => $type)
                        @php
                            $tc = $backupColors[$type['status']];
                            $lastLabel = $type['last_at'] ?? 'never';
                        @endphp
                        <div class="border border-gray-700/50 rounded-lg px-3 py-2 text-xs bg-black/30">
                            <div class="flex items-center justify-between mb-1">
                                <span class="font-semibold text-gray-300">{{ $type['label'] }}</span>
                                <span class="{{ $tc['text'] }}">{{ $tc['icon'] }} {{ $type['status'] }}</span>
                            </div>
                            <div class="text-gray-500">last run: <span class="text-gray-400">{{ $lastLabel }}</span></div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ITERATION 4: Onboarding funnel + TTFE. Was weekly-console-report-only —
             the product's headline metric (time to first published exhibition)
             is now visible continuously. Data: OnboardingMetricsService (cached 30/60 min). --}}
        <div class="mb-8 bg-gray-900/50 border border-gray-700/30 rounded-lg p-4">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">📈 Onboarding Funnel &amp; TTFE</h3>
                <div class="flex gap-1">
                    @foreach([7, 30, 90] as $period)
                        <a href="{{ route('super.index', ['days' => $period]) }}"
                           class="px-3 py-1 rounded-md text-xs font-medium {{ $onboardingDays === $period ? 'bg-red-600 text-white' : 'bg-gray-800 text-gray-400 hover:bg-gray-700' }}">
                            {{ $period }}d
                        </a>
                    @endforeach
                </div>
            </div>

            @php
                $funnel = [
                    ['label' => 'Registered',       'value' => $onboarding['registered'],      'color' => 'bg-blue-500'],
                    ['label' => 'Created gallery',  'value' => $onboarding['created_gallery'], 'color' => 'bg-indigo-500'],
                    ['label' => 'Uploaded image',   'value' => $onboarding['uploaded_image'],  'color' => 'bg-purple-500'],
                    ['label' => 'Published',        'value' => $onboarding['published'],       'color' => 'bg-emerald-500'],
                    ['label' => 'Got first view',   'value' => $onboarding['got_views'],       'color' => 'bg-amber-500'],
                ];
                $maxFunnel = max(1, $onboarding['registered']);
            @endphp

            <div class="space-y-2 mb-5">
                @foreach($funnel as $stage)
                    <div class="flex items-center gap-3">
                        <div class="w-32 text-xs text-gray-400 text-right shrink-0">{{ $stage['label'] }}</div>
                        <div class="flex-1 bg-gray-800/60 rounded-full h-5 overflow-hidden">
                            <div class="{{ $stage['color'] }} h-5 rounded-full"
                                 style="width: {{ min(100, (int) round(($stage['value'] / $maxFunnel) * 100)) }}%"></div>
                        </div>
                        <div class="w-28 text-xs text-gray-300 shrink-0">
                            {{ number_format($stage['value']) }}
                            <span class="text-gray-500">
                                ({{ $onboarding['registered'] > 0 ? round(($stage['value'] / $onboarding['registered']) * 100, 1) : 0 }}%)
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="bg-black/40 border border-gray-700/50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">Time to first published exhibition (TTFE)</div>
                    @if($onboarding['ttfe_hours'])
                        <div class="text-2xl font-bold text-emerald-300">
                            {{ $onboarding['ttfe_hours']['avg'] >= 48
                                ? round($onboarding['ttfe_hours']['avg'] / 24, 1) . ' days avg'
                                : $onboarding['ttfe_hours']['avg'] . ' hours avg' }}
                        </div>
                        <div class="text-xs text-gray-500 mt-0.5">
                            min {{ $onboarding['ttfe_hours']['min'] }}h · max {{ $onboarding['ttfe_hours']['max'] }}h ·
                            {{ $onboarding['published'] }} publisher{{ $onboarding['published'] === 1 ? '' : 's' }} in the window
                        </div>
                    @else
                        <div class="text-sm text-gray-500">No published exhibitions in this window yet.</div>
                    @endif
                </div>
                <div class="bg-black/40 border border-gray-700/50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">Time to first gallery (TTFG)</div>
                    @if($onboarding['ttfg_hours'])
                        <div class="text-2xl font-bold text-blue-300">
                            {{ $onboarding['ttfg_hours']['avg'] >= 48
                                ? round($onboarding['ttfg_hours']['avg'] / 24, 1) . ' days avg'
                                : $onboarding['ttfg_hours']['avg'] . ' hours avg' }}
                        </div>
                        <div class="text-xs text-gray-500 mt-0.5">
                            min {{ $onboarding['ttfg_hours']['min'] }}h · max {{ $onboarding['ttfg_hours']['max'] }}h ·
                            {{ $onboarding['created_gallery'] }} creator{{ $onboarding['created_gallery'] === 1 ? '' : 's' }} in the window
                        </div>
                    @else
                        <div class="text-sm text-gray-500">No galleries created in this window yet.</div>
                    @endif
                </div>
            </div>

            {{-- ITERATION 5: TTFE trend — weekly snapshots persisted by
                 exospace:onboarding-analytics. One point per week per window;
                 the chart appears from the second snapshot on.
                 ITERATION 6: release markers (dashed verticals + version
                 labels) from ReleaseCalendar — the changelog's own release
                 dates, so metric movement can be read against what shipped.
                 ITERATION 7: >2σ anomaly rings (amber for high/worse,
                 emerald for low/better) — weeks that deviate from the
                 trailing mean with no release to blame. --}}
            <div class="bg-black/40 border border-gray-700/50 rounded-lg p-3 mt-3">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wider">TTFE / TTFG trend — weekly snapshots ({{ $onboardingDays }}d window)</div>
                    <div class="text-[10px] text-gray-600">{{ count($onboardingTrend) }} point{{ count($onboardingTrend) === 1 ? '' : 's' }} recorded{{ count($releaseAnnotations) > 0 ? ' · ' . count($releaseAnnotations) . ' release marker' . (count($releaseAnnotations) === 1 ? '' : 's') : '' }}{{ count($anomalyAnnotations ?? []) > 0 ? ' · ' . count($anomalyAnnotations) . ' anomal' . (count($anomalyAnnotations) === 1 ? 'y' : 'ies') : '' }}</div>
                </div>
                @if(count($onboardingTrend) >= 2)
                    @php
                        // ITERATION 8: canvas accessibility — WCAG 1.1.1 (canvas
                        //   needs a text alternative). The aria-label is computed
                        //   server-side from the trend + annotation counts so a
                        //   screen reader announces point count + release
                        //   markers + anomaly count (the WHERE is in the table
                        //   fallback below the chart).
                        $ttfePoints = count($onboardingTrend);
                        $ttfeReleases = count($releaseAnnotations);
                        $ttfeAnomalies = count($anomalyAnnotations ?? []);
                        $ttfeAria = "TTFE trend chart, {$ttfePoints} weekly snapshot" . ($ttfePoints === 1 ? '' : 's');
                        if ($ttfeReleases > 0) { $ttfeAria .= ", {$ttfeReleases} release marker" . ($ttfeReleases === 1 ? '' : 's'); }
                        if ($ttfeAnomalies > 0) { $ttfeAria .= ", {$ttfeAnomalies} anomal" . ($ttfeAnomalies === 1 ? 'y' : 'ies'); }
                    @endphp
                    <div class="h-56"><canvas id="ttfe-trend-chart" role="img" aria-label="{{ $ttfeAria }}"></canvas></div>
                    <div class="text-[10px] text-gray-600 mt-1">Average hours from signup to first gallery (TTFG) and first published exhibition (TTFE). Lower is better. Dashed lines mark releases (from the /changelog calendar). Amber/emerald rings mark weeks that deviate >2σ from the trailing mean.</div>
                @else
                    <div class="text-sm text-gray-500 py-6 text-center">
                        Trend appears after the second weekly snapshot — the first is already recorded and will chart next Monday.
                    </div>
                @endif
            </div>

            {{-- ITERATION 9 — funnel-stage conversion-rate trend + >2σ
                 anomaly rings per stage. The 5-bar funnel above is a point
                 value (one window); this chart shows the per-stage conversion
                 rate (registered→created_gallery, created_gallery→uploaded_image,
                 uploaded_image→published, published→got_views) over time so a
                 sudden stage drop ("this week only 10% of new signups created a
                 gallery vs the 30% trailing avg") surfaces as an amber ring at
                 the right week. Same TrendAnomalies::detect algorithm + same
                 ring-draw plugin pattern as the TTFE + retention charts; the
                 per-stage tooltip override (workstream C) renders the breakdown
                 when hovering a ringed point. --}}
            @if(!empty($funnelStageTrend) && count($onboardingTrend) >= 2)
                @php
                    $fsPoints = count($onboardingTrend);
                    $fsAnomalyTotal = collect($funnelStageTrend)->sum(fn ($s) => count($s['anomalies']));
                    $fsAria = "Funnel-stage conversion trend chart, {$fsPoints} weekly snapshot" . ($fsPoints === 1 ? '' : 's') . ", 4 stages";
                    if ($fsAnomalyTotal > 0) { $fsAria .= ", {$fsAnomalyTotal} anomal" . ($fsAnomalyTotal === 1 ? 'y' : 'ies'); }
                @endphp
                <div class="bg-black/40 border border-gray-700/50 rounded-lg p-3 mt-3">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs text-gray-500 uppercase tracking-wider">Funnel-stage conversion trend — weekly snapshots ({{ $onboardingDays }}d window)</div>
                        <div class="text-[10px] text-gray-600">{{ $fsPoints }} point{{ $fsPoints === 1 ? '' : 's' }} recorded · 4 stages{{ $fsAnomalyTotal > 0 ? ' · ' . $fsAnomalyTotal . ' anomal' . ($fsAnomalyTotal === 1 ? 'y' : 'ies') : '' }}</div>
                    </div>
                    <div class="h-56"><canvas id="funnel-stage-trend-chart" role="img" aria-label="{{ $fsAria }}"></canvas></div>
                    <div class="text-[10px] text-gray-600 mt-1">Conversion rate per funnel stage, weekly. Higher is better. Amber rings mark weeks a stage rate drops >2σ below the trailing mean (worse — stage drop); emerald rings mark weeks a stage rate rises >2σ above (better).</div>
                </div>
            @endif
        </div>

        {{-- ITERATION 6: Cohort retention — was a weekly stdout-only report
             (the same blindness TTFE had before Iteration 5). Live matrix from
             CohortRetentionMetricsService (cached 30/60 min); truthful bounded
             activity: a login (users.last_login_at) OR a gallery update in the
             week. Trend from retention_snapshots persisted weekly. --}}
        <div class="mb-8 bg-gray-900/50 border border-gray-700/30 rounded-lg p-4">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">🔁 Weekly cohort retention</h3>
                <div class="text-[10px] text-gray-600">active = login or gallery update in the week · * = week not closed yet</div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-gray-500">
                            <th class="text-left py-1.5 pr-3 font-medium">Cohort</th>
                            <th class="text-right py-1.5 px-2 font-medium">Size</th>
                            @foreach(range(0, $retention['weeks'] - 1) as $w)
                                <th class="text-right py-1.5 px-2 font-medium">W{{ $w }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($retention['cohorts'] as $cohort)
                            <tr class="border-t border-gray-800/60">
                                <td class="py-1.5 pr-3 text-gray-300 whitespace-nowrap">{{ $cohort['label'] }}</td>
                                <td class="py-1.5 px-2 text-right text-gray-400">{{ number_format($cohort['size']) }}</td>
                                @foreach($cohort['cells'] as $w => $cell)
                                    @php
                                        // Heat shading: deeper emerald = better retention; partial
                                        // (not-yet-closed) weeks render dimmed with an asterisk so a
                                        // still-running week can never read as a final rate.
                                        $pct = (float) $cell['pct'];
                                        $shade = $pct >= 40 ? 'bg-emerald-900/60 text-emerald-200'
                                                  : ($pct >= 20 ? 'bg-emerald-900/30 text-emerald-300'
                                                  : ($pct >= 10 ? 'bg-amber-900/30 text-amber-300' : 'text-gray-600'));
                                        if (! $cell['complete']) { $shade .= ' opacity-50'; }
                                        // ITERATION 7: cells with a non-empty cohort link to the
                                        // drill-down — size-0 cohorts have nothing behind the number.
                                        $hasDrilldown = $cohort['size'] > 0 && $cell['complete'];
                                        $drilldownUrl = $hasDrilldown
                                            ? route('super.retention.cohort', ['cohort' => $cohort['week_start'], 'week' => $w])
                                            : null;
                                    @endphp
                                    <td class="py-1.5 px-2 text-right rounded {{ $shade }} {{ $cell['complete'] ? '' : 'italic' }} {{ $hasDrilldown ? 'hover:ring-1 hover:ring-emerald-500/50 hover:cursor-pointer' : '' }}">
                                        @if($hasDrilldown)
                                            <a href="{{ $drilldownUrl }}" class="block" title="View the {{ $cohort['size'] }} member(s) in this cohort ({{ $cell['active'] }} active in week {{ $w }})">{{ $pct . '%' }}{{ $cell['complete'] ? '' : '*' }}</a>
                                        @else
                                            {{ $cohort['size'] > 0 ? $pct . '%' : '–' }}{{ $cell['complete'] ? '' : '*' }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- W1/W2 retention trend — weekly snapshots persisted by
                 exospace:cohort-retention. One point per capture: the retention
                 of the most recent cohort whose week had closed by then. --}}
            <div class="bg-black/40 border border-gray-700/50 rounded-lg p-3 mt-3">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wider">Week-1 / Week-2 retention trend — weekly snapshots</div>
                    <div class="text-[10px] text-gray-600">{{ count($retentionTrendW1) }} point{{ count($retentionTrendW1) === 1 ? '' : 's' }} recorded{{ (count($retentionW1Anomalies ?? []) + count($retentionW2Anomalies ?? [])) > 0 ? ' · ' . (count($retentionW1Anomalies ?? []) + count($retentionW2Anomalies ?? [])) . ' anomal' . ((count($retentionW1Anomalies ?? []) + count($retentionW2Anomalies ?? [])) === 1 ? 'y' : 'ies') : '' }}</div>
                </div>
                @if(count($retentionTrendW1) >= 2)
                    @php
                        // ITERATION 8: canvas accessibility (mirrors the TTFE
                        //   chart's role="img" + aria-label). Anomaly count is
                        //   the SUM of W1 + W2 anomalies — both series are
                        //   rendered on the same chart, so the screen-reader
                        //   announcement must cover both.
                        $retPoints = count($retentionTrendW1);
                        $retAnomalies = count($retentionW1Anomalies ?? []) + count($retentionW2Anomalies ?? []);
                        $retAria = "Week-1 and Week-2 retention trend chart, {$retPoints} weekly snapshot" . ($retPoints === 1 ? '' : 's');
                        if ($retAnomalies > 0) { $retAria .= ", {$retAnomalies} anomal" . ($retAnomalies === 1 ? 'y' : 'ies'); }
                    @endphp
                    <div class="h-56"><canvas id="retention-trend-chart" role="img" aria-label="{{ $retAria }}"></canvas></div>
                    <div class="text-[10px] text-gray-600 mt-1">% of each cohort active (login or gallery update) in their 1st / 2nd week after registration. Higher is better. Amber rings mark weeks that drop >2σ below the trailing mean (churn up); emerald rings mark weeks that rise >2σ above.</div>
                @else
                    <div class="text-sm text-gray-500 py-6 text-center">
                        Trend appears after the second weekly snapshot — the first is already recorded and will chart next Monday.
                    </div>
                @endif
            </div>
        </div>

        <!-- Search / Filter -->
        <div class="flex gap-3 mb-4">
            <input type="text" id="userSearch" placeholder="Search by name or email..."
                   class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-sm text-white placeholder-gray-500 focus:border-red-500 outline-none">
            <select id="planFilter" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-300 focus:border-red-500 outline-none">
                <option value="">All Plans</option>
                <option value="free">Free</option>
                <option value="pro">Pro</option>
                <option value="studio">Studio</option>
            </select>
            <select id="statusFilter" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-300 focus:border-red-500 outline-none">
                <option value="">All Status</option>
                <option value="banned">Banned</option>
                <option value="unverified">Unverified</option>
                <option value="verified">Verified</option>
            </select>
        </div>

        <!-- Users Table -->
        <h2 class="text-xl font-bold mb-4 text-gray-300 uppercase tracking-wider text-sm">👥 All Users</h2>
        <div class="bg-black/40 border border-gray-700 rounded-xl overflow-hidden">
            <table class="w-full" id="usersTable">
                <thead class="bg-gray-800/60 border-b border-gray-700">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-400 uppercase">User</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Plan</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Galleries</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Joined</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800" id="usersBody">
                    @foreach($users as $user)
                    @php
                        $isBanned    = ! is_null($user->banned_at);
                        $isVerified  = ! is_null($user->email_verified_at);
                        $isSelf      = $user->id === auth()->id();
                    @endphp
                    <tr class="hover:bg-gray-800/20 transition user-row"
                        data-name="{{ strtolower($user->name) }}"
                        data-email="{{ strtolower($user->email) }}"
                        data-plan="{{ $user->plan }}"
                        data-banned="{{ $isBanned ? 'banned' : '' }}"
                        data-verified="{{ $isVerified ? 'verified' : 'unverified' }}">

                        {{-- User --}}
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center font-bold text-sm flex-shrink-0">
                                    {{ strtoupper(substr($user->name, 0, 1)) }}
                                </div>
                                <div>
                                    <div class="font-medium text-white flex items-center gap-2">
                                        {{ $user->name }}
                                        @if($isSelf)
                                            <span class="text-xs bg-blue-600 px-1.5 py-0.5 rounded">YOU</span>
                                        @endif
                                        @if($user->is_super_admin)
                                            <span class="text-xs bg-red-600 px-1.5 py-0.5 rounded">ADMIN</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-gray-400">{{ $user->email }}</div>
                                </div>
                            </div>
                        </td>

                        {{-- Status badges --}}
                        <td class="px-5 py-4">
                            <div class="flex flex-col gap-1">
                                @if($isBanned)
                                    <span class="text-xs bg-red-900/60 border border-red-700/50 text-red-300 px-2 py-0.5 rounded-full w-fit">🚫 Banned</span>
                                @endif
                                @if($isVerified)
                                    <span class="text-xs bg-green-900/40 border border-green-700/30 text-green-400 px-2 py-0.5 rounded-full w-fit">✓ Verified</span>
                                @else
                                    <span class="text-xs bg-yellow-900/40 border border-yellow-700/30 text-yellow-400 px-2 py-0.5 rounded-full w-fit">⚠ Unverified</span>
                                @endif
                            </div>
                        </td>

                        {{-- Plan --}}
                        <td class="px-5 py-4">
                            @if(! $isSelf)
                            <form method="POST" action="{{ route('super.updatePlan', $user) }}">
                                @csrf
                                <select name="plan" data-change="confirmChangePlan" data-arg="Change plan for {{ $user->name }}?"
                                        class="bg-gray-700 border border-gray-600 rounded-lg px-2 py-1 text-xs text-white focus:border-red-500 outline-none">
                                    <option value="free"   {{ $user->plan === 'free'   ? 'selected' : '' }}>FREE</option>
                                    <option value="pro"    {{ $user->plan === 'pro'    ? 'selected' : '' }}>PRO</option>
                                    <option value="studio" {{ $user->plan === 'studio' ? 'selected' : '' }}>STUDIO</option>
                                </select>
                            </form>
                            @else
                                <span class="text-xs px-2 py-1 rounded-lg
                                    {{ $user->plan === 'free' ? 'bg-gray-700 text-gray-300' : '' }}
                                    {{ $user->plan === 'pro' ? 'bg-yellow-800/60 text-yellow-300' : '' }}
                                    {{ $user->plan === 'studio' ? 'bg-purple-800/60 text-purple-300' : '' }}">
                                    {{ strtoupper($user->plan) }}
                                </span>
                            @endif
                        </td>

                        {{-- Galleries --}}
                        <td class="px-5 py-4">
                            <a href="{{ route('super.user-galleries', $user) }}" class="text-blue-400 hover:text-blue-300 text-sm transition">
                                {{ $user->galleries_count }} galleries →
                            </a>
                        </td>

                        {{-- Joined --}}
                        <td class="px-5 py-4 text-gray-400 text-xs">
                            {{ $user->created_at->format('M j, Y') }}<br>
                            <span class="text-gray-600">{{ $user->created_at->diffForHumans() }}</span>
                        </td>

                        {{-- Actions --}}
                        <td class="px-5 py-4">
                            @if(! $isSelf)
                            <div class="flex flex-wrap gap-1.5">

                                {{-- Ban / Unban --}}
                                @if($isBanned)
                                    <form method="POST" action="{{ route('super.unbanUser', $user) }}">
                                        @csrf
                                        <button type="submit"
                                                data-confirm-click="Unban {{ $user->name }}?"
                                                class="px-3 py-1.5 bg-green-700 hover:bg-green-600 rounded-lg text-xs transition">
                                            ✅ Unban
                                        </button>
                                    </form>
                                @else
                                    <button data-click="openBanModal" data-args='[{{ $user->id }}, {{ json_encode($user->name) }}]'
                                            class="px-3 py-1.5 bg-orange-700 hover:bg-orange-600 rounded-lg text-xs transition">
                                        🚫 Ban
                                    </button>
                                @endif

                                {{-- Verify / Unverify email --}}
                                @if(! $isVerified)
                                    <form method="POST" action="{{ route('super.verifyEmail', $user) }}">
                                        @csrf
                                        <button type="submit"
                                                data-confirm-click="Manually verify email for {{ $user->name }}?"
                                                class="px-3 py-1.5 bg-teal-700 hover:bg-teal-600 rounded-lg text-xs transition">
                                            ✉ Verify
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('super.unverifyEmail', $user) }}">
                                        @csrf
                                        <button type="submit"
                                                data-confirm-click="Revoke email verification for {{ $user->name }}? They will need to verify again."
                                                class="px-3 py-1.5 bg-gray-600 hover:bg-gray-500 rounded-lg text-xs transition">
                                            ✉ Unverify
                                        </button>
                                    </form>
                                @endif

                                {{-- Toggle Super Admin --}}
                                @if(! $user->is_super_admin)
                                    <button type="button"
                                            data-click="openAdminModal" data-args='[{{ $user->id }}, {{ json_encode($user->name) }}, "grant"]'
                                            class="px-3 py-1.5 bg-purple-800 hover:bg-purple-700 rounded-lg text-xs transition">
                                        👑 Make Admin
                                    </button>
                                @else
                                    <button type="button"
                                            data-click="openAdminModal" data-args='[{{ $user->id }}, {{ json_encode($user->name) }}, "revoke"]'
                                            class="px-3 py-1.5 bg-purple-900/50 border border-purple-700 hover:bg-purple-800 rounded-lg text-xs transition text-purple-300">
                                        👑 Revoke Admin
                                    </button>
                                @endif

                                {{-- M-13: Impersonate (Login As User) --}}
                                @featureFlag('admin_impersonation')
                                @if(! $user->is_super_admin)
                                    <form method="POST" action="{{ route('super.impersonate', $user) }}">
                                        @csrf
                                        <button type="submit"
                                                data-confirm-click="Log in as {{ $user->name }}? You will see the site from their perspective. Click &quot;Return to admin&quot; to stop."
                                                class="px-3 py-1.5 bg-indigo-700 hover:bg-indigo-600 rounded-lg text-xs transition">
                                            🔑 Login As
                                        </button>
                                    </form>
                                @endif
                                @endfeatureFlag

                                {{-- Delete --}}
                                @if(! $user->is_super_admin)
                                    <button type="button"
                                            data-click="openDeleteModal" data-args='[{{ $user->id }}, {{ json_encode($user->name) }}]'
                                            class="px-3 py-1.5 bg-red-700 hover:bg-red-600 rounded-lg text-xs transition">
                                        🗑 Delete
                                    </button>
                                @endif

                            </div>
                            @else
                                <span class="text-xs text-gray-600">— your account —</span>
                            @endif

                            {{-- Ban reason tooltip --}}
                            @if($isBanned && $user->ban_reason)
                                <div class="mt-1 text-xs text-red-400/70 italic">
                                    Reason: {{ Str::limit($user->ban_reason, 60) }}
                                </div>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Ban Modal -->
    <div id="banModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden flex items-center justify-center px-4">
        <div class="bg-gray-900 border border-red-700/50 rounded-2xl p-6 w-full max-w-md shadow-2xl">
            <h3 class="text-lg font-bold text-white mb-1">Ban User</h3>
            <p class="text-gray-400 text-sm mb-4">Banning <strong id="banUserName" class="text-white"></strong>. They will be blocked from logging in.</p>
            <form id="banForm" method="POST">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm text-gray-400 mb-1.5">Reason <span class="text-gray-600">(optional)</span></label>
                    <textarea name="reason" rows="3" placeholder="e.g. Violation of terms of service"
                              class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm placeholder-gray-600 focus:border-red-500 outline-none resize-none"></textarea>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 py-2.5 rounded-lg font-medium transition text-sm">
                        🚫 Confirm Ban
                    </button>
                    <button type="button" data-click="closeBanModal" class="px-5 bg-gray-700 hover:bg-gray-600 py-2.5 rounded-lg transition text-sm">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="@nonce">
        // ── CSP-safe delegated action handlers (mirrors layouts/app.blade.php) ──
        // Replaces inline onclick/onchange/onsubmit with declarative data-* attrs.
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('form[data-confirm]').forEach(form => {
                form.addEventListener('submit', (e) => {
                    if (!window.confirm(form.dataset.confirm)) e.preventDefault();
                });
            });
            document.querySelectorAll('[data-confirm-click]').forEach(el => {
                el.addEventListener('click', (e) => {
                    if (!window.confirm(el.dataset.confirmClick)) e.preventDefault();
                });
            });
            const delegate = (eventName, attr) => {
                document.addEventListener(eventName, (e) => {
                    const el = e.target.closest(`[${attr}]`);
                    if (!el) return;
                    const fn = window[el.getAttribute(attr)];
                    if (typeof fn !== 'function') return;
                    if (el.dataset.args) {
                        try { fn.call(el, ...JSON.parse(el.dataset.args), e); }
                        catch (err) { console.warn('[data-action] invalid JSON args:', el.dataset.args, err); }
                    } else if (el.dataset.arg !== undefined) {
                        fn.call(el, el.dataset.arg, e);
                    } else {
                        fn.call(el, el, e);
                    }
                });
            };
            delegate('click', 'data-click');
            delegate('change', 'data-change');
            delegate('input', 'data-input');
            delegate('submit', 'data-submit');
        });

        // CSP-safe delegated change handler: confirm + submit form
        window.confirmChangePlan = function(message, e) {
            if (window.confirm(message)) {
                const form = e.target.closest('form');
                if (form) form.submit();
            }
        };

        // Ban modal
        function openBanModal(userId, userName) {
            document.getElementById('banUserName').textContent = userName;
            document.getElementById('banForm').action = '/master-control/users/' + userId + '/ban';
            document.getElementById('banModal').classList.remove('hidden');
        }
        function closeBanModal() {
            document.getElementById('banModal').classList.add('hidden');
        }
        document.getElementById('banModal').addEventListener('click', function(e) {
            if (e.target === this) closeBanModal();
        });

        // Search & filter
        const search     = document.getElementById('userSearch');
        const planFilter = document.getElementById('planFilter');
        const statusFilter = document.getElementById('statusFilter');

        function applyFilters() {
            const q      = search.value.toLowerCase();
            const plan   = planFilter.value;
            const status = statusFilter.value;

            document.querySelectorAll('.user-row').forEach(row => {
                const matchesSearch = ! q || row.dataset.name.includes(q) || row.dataset.email.includes(q);
                const matchesPlan   = ! plan || row.dataset.plan === plan;
                const matchesStatus = ! status
                    || (status === 'banned'     && row.dataset.banned === 'banned')
                    || (status === 'unverified' && row.dataset.verified === 'unverified')
                    || (status === 'verified'   && row.dataset.verified === 'verified');

                row.style.display = (matchesSearch && matchesPlan && matchesStatus) ? '' : 'none';
            });
        }

        search.addEventListener('input', applyFilters);
        planFilter.addEventListener('change', applyFilters);
        statusFilter.addEventListener('change', applyFilters);
    </script>


    {{-- (Task H32) Type-to-confirm modals for destructive super-admin actions --}}
    <div id="deleteConfirmModal" x-data="{ open: false, typed: '', userId: 0, userName: '' }"
         x-cloak
         class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[60] hidden items-center justify-center p-4"
         role="dialog" aria-modal="true" aria-labelledby="delete-modal-heading"
         :class="open ? 'flex' : 'hidden'"
         @keydown.escape.window="open = false; typed = ''"
         @click.self="open = false; typed = ''">
        <div class="bg-gray-900 border border-red-700/50 rounded-2xl max-w-md w-full shadow-2xl p-6 relative">
            <button @click="open = false; typed = ''" class="absolute top-3 right-3 text-gray-500 hover:text-gray-300" aria-label="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <h3 id="delete-modal-heading" class="text-lg font-bold text-red-400 mb-3">Permanently Delete User</h3>
            <div class="text-sm text-gray-400 mb-4 space-y-2">
                <p>You are about to <strong class="text-red-400">permanently delete</strong> <strong x-text="userName" class="text-white"></strong>.</p>
                <p>This will delete:</p>
                <ul class="list-disc list-inside text-gray-500 ml-2 space-y-0.5">
                    <li>User account</li>
                    <li>All personal galleries &amp; images</li>
                    <li>All teams they own</li>
                    <li>All files from storage</li>
                </ul>
                <p class="text-red-400 font-semibold">This CANNOT be undone.</p>
            </div>
            <div class="bg-gray-800/50 rounded-lg p-3 mb-4">
                <label for="delete-confirm-input" class="block text-xs text-gray-500 mb-1">
                    Type <code class="text-gray-300 font-mono">DELETE</code> to confirm
                </label>
                <input id="delete-confirm-input" type="text" x-model="typed" :placeholder="userName"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none"
                    autocomplete="off">
            </div>
            <form :action="'/master-control/users/' + userId" method="POST" id="deleteForm">
                @csrf
                <input type="hidden" name="_method" value="DELETE">
                <div class="flex gap-3">
                    <button type="button" @click="open = false; typed = ''"
                            class="flex-1 bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-400 font-medium py-2.5 rounded-xl transition text-sm">Cancel</button>
                    <button type="submit" :disabled="typed !== 'DELETE'"
                            class="flex-1 bg-red-600 hover:bg-red-500 text-white font-bold py-2.5 rounded-xl transition text-sm disabled:opacity-40 disabled:cursor-not-allowed">Delete User</button>
                </div>
            </form>
        </div>
    </div>

    <div id="adminConfirmModal" x-data="{ open: false, typed: '', userId: 0, userName: '', action: 'grant' }"
         x-cloak
         class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[60] hidden items-center justify-center p-4"
         role="dialog" aria-modal="true" aria-labelledby="admin-modal-heading"
         :class="open ? 'flex' : 'hidden'"
         @keydown.escape.window="open = false; typed = ''"
         @click.self="open = false; typed = ''">
        <div class="bg-gray-900 border border-purple-700/50 rounded-2xl max-w-md w-full shadow-2xl p-6 relative">
            <button @click="open = false; typed = ''" class="absolute top-3 right-3 text-gray-500 hover:text-gray-300" aria-label="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <h3 id="admin-modal-heading" class="text-lg font-bold text-purple-400 mb-3"
                x-text="action === 'grant' ? 'Grant Super Admin' : 'Revoke Super Admin'"></h3>
            <div class="text-sm text-gray-400 mb-4 space-y-2">
                <p x-show="action === 'grant'">
                    You are about to grant <strong class="text-purple-400">super admin access</strong> to <strong x-text="userName" class="text-white"></strong>.
                    They will have full platform access including the ability to delete users, change plans, and modify any gallery.
                </p>
                <p x-show="action === 'revoke'">
                    You are about to <strong class="text-purple-400">revoke super admin access</strong> from <strong x-text="userName" class="text-white"></strong>.
                    They will lose access to /master-control/* immediately.
                </p>
            </div>
            <div class="bg-gray-800/50 rounded-lg p-3 mb-4">
                <label for="admin-confirm-input" class="block text-xs text-gray-500 mb-1">
                    Type <code class="text-gray-300 font-mono" x-text="action === 'grant' ? 'GRANT' : 'REVOKE'"></code> to confirm
                </label>
                <input id="admin-confirm-input" type="text" x-model="typed"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none"
                    autocomplete="off">
            </div>
            <form :action="'/master-control/users/' + userId + '/toggle-super-admin'" method="POST" id="adminForm">
                @csrf
                <div class="flex gap-3">
                    <button type="button" @click="open = false; typed = ''"
                            class="flex-1 bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-400 font-medium py-2.5 rounded-xl transition text-sm">Cancel</button>
                    <button type="submit"
                            :disabled="(action === 'grant' && typed !== 'GRANT') || (action === 'revoke' && typed !== 'REVOKE')"
                            class="flex-1 bg-purple-600 hover:bg-purple-500 text-white font-bold py-2.5 rounded-xl transition text-sm disabled:opacity-40 disabled:cursor-not-allowed"
                            x-text="action === 'grant' ? 'Grant Access' : 'Revoke Access'"></button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="@nonce">
    // ITERATION 5: TTFE trend chart. Chart.js loads as a Vite module
    // (admin-vendor.js) — under Turbo Drive it can still be evaluating when
    // this classic script runs, so poll for window.Chart instead of assuming
    // it (same waitForChartThenInit pattern as the gallery analytics page).
    //
    // ITERATION 6: release annotations — a tiny inline plugin (the Chart.js
    // annotation package is NOT in the admin-vendor bundle) draws a dashed
    // vertical + version label at the first capture at/after each release
    // date. Same release list /changelog renders (ReleaseCalendar service).
    //
    // ITERATION 7: >2σ anomaly rings — a second inline plugin draws an
    // amber ring (high = worse) or emerald ring (low = better) around any
    // weekly TTFE point that deviates more than 2σ from the trailing mean.
    // Math lives in TrendAnomalies::detect (PHP-side); JS only draws the
    // pre-computed {index, z, direction} list so the canvas and the audit
    // trail always agree.
    (function () {
        var canvas = document.getElementById('ttfe-trend-chart');
        if (!canvas) return; // fewer than 2 snapshots — placeholder shown

        var labels = @json(collect($onboardingTrend)->pluck('captured_at'));
        var captureDates = @json(collect($onboardingTrend)->pluck('captured_on'));
        var ttfe = @json(collect($onboardingTrend)->pluck('ttfe_avg'));
        var ttfg = @json(collect($onboardingTrend)->pluck('ttfg_avg'));
        var releases = @json($releaseAnnotations);
        var anomalies = @json($anomalyAnnotations ?? []);

        // Map each release to a chart index: the first capture point at or
        // after the release date (a release between two Mondays annotates
        // the first Monday that could reflect it).
        var releaseMarks = [];
        releases.forEach(function (release) {
            var idx = captureDates.findIndex(function (d) { return d >= release.date; });
            if (idx === -1) idx = captureDates.length - 1;
            if (releaseMarks.every(function (m) { return m.index !== idx; })) {
                releaseMarks.push({ index: idx, label: release.version });
            }
        });

        function waitForChart(attemptsLeft) {
            if (window.Chart) { initTrendChart(); return; }
            if (attemptsLeft <= 0) {
                console.error('Chart.js failed to load (admin-vendor.js) — TTFE trend not rendered.');
                return;
            }
            setTimeout(function () { waitForChart(attemptsLeft - 1); }, 100);
        }

        // Inline plugin: dashed vertical + rotated label at the top of the
        // chart area. Pure Chart.js plugin API — no annotation package.
        var releaseAnnotationPlugin = {
            id: 'releaseAnnotations',
            afterDatasetsDraw: function (chart) {
                var ctx = chart.ctx;
                var chartArea = chart.chartArea;
                var xAxis = chart.scales.x;

                releaseMarks.forEach(function (mark, i) {
                    var x = xAxis.getPixelForValue(mark.index);
                    if (x < chartArea.left || x > chartArea.right) return;

                    ctx.save();
                    ctx.strokeStyle = 'rgba(244,114,182,0.55)'; // rose-400, visible against both datasets
                    ctx.setLineDash([4, 4]);
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(x, chartArea.top);
                    ctx.lineTo(x, chartArea.bottom);
                    ctx.stroke();
                    ctx.setLineDash([]);

                    ctx.fillStyle = 'rgba(244,114,182,0.9)';
                    ctx.font = '10px sans-serif';
                    ctx.textAlign = 'left';
                    // Stack labels when multiple releases land near each other.
                    ctx.fillText(mark.label, x + 3, chartArea.top + 6 + (i % 3) * 12);
                    ctx.restore();
                });
            }
        };

        // ITERATION 7: Inline plugin — ring anomalous TTFE points. Amber
        // for high (worse: slower TTFE), emerald for low (better: faster
        // TTFE). Ring radius 7 sits around the standard point radius (3)
        // so it never obscures the underlying data. Z-label sits above
        // high anomalies and below low ones, off the data line.
        //
        // ITERATION 8: sigma + sigma_eff are now forwarded by
        // SystemController alongside z (audit-fix D-4). A future
        // iteration can wire these into a Chart.js tooltip override
        // (the canvas title attribute is canvas-wide, not per-shape, so
        // a real tooltip plugin is the right vehicle — deferred; the
        // data is available in the `anomalies` JS variable in the
        // meantime, and the math is documented in
        // app/Services/TrendAnomalies.php for hand-recomputation).
        var anomalyPlugin = {
            id: 'ttfeAnomalies',
            afterDatasetsDraw: function (chart) {
                if (!anomalies.length) return;
                var ctx = chart.ctx;
                var chartArea = chart.chartArea;
                var xAxis = chart.scales.x;
                var yAxis = chart.scales.y;

                anomalies.forEach(function (a) {
                    var x = xAxis.getPixelForValue(a.index);
                    var y = yAxis.getPixelForValue(a.value);
                    if (x < chartArea.left || x > chartArea.right) return;

                    var isHigh = a.direction === 'high';
                    var color = isHigh ? 'rgba(251,191,36,0.95)' : 'rgba(52,211,153,0.95)'; // amber-400 / emerald-400

                    ctx.save();
                    ctx.strokeStyle = color;
                    ctx.lineWidth = 2;
                    ctx.beginPath();
                    ctx.arc(x, y, 7, 0, Math.PI * 2);
                    ctx.stroke();

                    ctx.fillStyle = color;
                    ctx.font = '10px sans-serif';
                    ctx.textAlign = 'center';
                    var sign = isHigh ? '+' : '-';
                    var labelY = isHigh ? y - 12 : y + 18;
                    ctx.fillText(sign + Math.abs(a.z) + 'sigma', x, labelY);
                    ctx.restore();
                });
            }
        };

        function initTrendChart() {
            new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'TTFE avg (hours)',
                            data: ttfe,
                            borderColor: '#34d399',
                            backgroundColor: 'rgba(52,211,153,0.08)',
                            borderWidth: 2,
                            pointRadius: 3,
                            pointBackgroundColor: '#34d399',
                            tension: 0.3,
                            spanGaps: true,
                            fill: true,
                        },
                        {
                            label: 'TTFG avg (hours)',
                            data: ttfg,
                            borderColor: '#60a5fa',
                            borderWidth: 2,
                            pointRadius: 2,
                            pointBackgroundColor: '#60a5fa',
                            tension: 0.3,
                            spanGaps: true,
                            fill: false,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: '#9ca3af', font: { size: 11 } } } },
                    scales: {
                        x: {
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            ticks: { color: '#6b7280', font: { size: 10 }, maxTicksLimit: 10 }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            ticks: { color: '#6b7280', font: { size: 10 } },
                            title: { display: true, text: 'hours', color: '#6b7280', font: { size: 10 } }
                        }
                    }
                },
                // Both plugins conditional on having at least one mark each
                // so a clean trend renders with no overlays.
                plugins: [].concat(
                    releaseMarks.length > 0 ? [releaseAnnotationPlugin] : [],
                    anomalies.length > 0 ? [anomalyPlugin] : []
                )
            });
        }

        waitForChart(30);
    })();

    // ITERATION 6: W1/W2 retention trend chart — same waitForChart pattern.
    // ITERATION 8: anomaly plugin rings low-retention weeks amber
    // (worse — churn up) and high-retention weeks emerald (better).
    // Direction convention is INVERTED vs TTFE: for retention, 'high'
    // = more users came back = better, so 'high' → emerald (the same
    // visual language as TTFE: amber = bad, emerald = good regardless
    // of which metric the ring annotates).
    (function () {
        var canvas = document.getElementById('retention-trend-chart');
        if (!canvas) return; // fewer than 2 snapshots — placeholder shown

        var labels = @json(collect($retentionTrendW1)->pluck('captured_at'));
        var w1 = @json(collect($retentionTrendW1)->pluck('retained_pct'));
        var w2 = @json(collect($retentionTrendW2)->pluck('retained_pct'));
        var w1Anomalies = @json($retentionW1Anomalies ?? []);
        var w2Anomalies = @json($retentionW2Anomalies ?? []);

        function waitForChart(attemptsLeft) {
            if (window.Chart) { initRetentionChart(); return; }
            if (attemptsLeft <= 0) {
                console.error('Chart.js failed to load (admin-vendor.js) — retention trend not rendered.');
                return;
            }
            setTimeout(function () { waitForChart(attemptsLeft - 1); }, 100);
        }

        // Ring anomalies on the retention trend. datasetIndex selects
        // which series (0 = W1 pink, 1 = W2 purple) the ring sits on.
        // Color INVERTED vs TTFE: 'low' (less retention) = amber/worse;
        // 'high' (more retention) = emerald/better.
        function makeRetentionAnomalyPlugin(id, datasetIndex, anomalies) {
            return {
                id: id,
                afterDatasetsDraw: function (chart) {
                    if (!anomalies.length) return;
                    var ctx = chart.ctx;
                    var chartArea = chart.chartArea;
                    var xAxis = chart.scales.x;
                    // For a 2-dataset line chart, the y-pixel of a point
                    // depends on the dataset it belongs to.
                    var yAxis = chart.scales.y;
                    var meta = chart.getDatasetMeta(datasetIndex);
                    if (!meta || !meta.data) return;

                    anomalies.forEach(function (a) {
                        var x = xAxis.getPixelForValue(a.index);
                        if (x < chartArea.left || x > chartArea.right) return;
                        // Find the y-pixel for this data point from the
                        // dataset's own rendered points (more reliable
                        // than recomputing from yAxis + the raw value
                        // when spanGaps/tension are in play).
                        var pt = meta.data[a.index];
                        if (!pt) return;
                        var y = pt.y;

                        // INVERTED vs TTFE: low retention = worse = amber.
                        var isLow = a.direction === 'low';
                        var color = isLow ? 'rgba(251,191,36,0.95)' : 'rgba(52,211,153,0.95)';

                        ctx.save();
                        ctx.strokeStyle = color;
                        ctx.lineWidth = 2;
                        ctx.beginPath();
                        ctx.arc(x, y, 7, 0, Math.PI * 2);
                        ctx.stroke();

                        ctx.fillStyle = color;
                        ctx.font = '10px sans-serif';
                        ctx.textAlign = 'center';
                        var sign = isLow ? '-' : '+';
                        var labelY = isLow ? y + 18 : y - 12;
                        ctx.fillText(sign + Math.abs(a.z) + 'sigma', x, labelY);
                        ctx.restore();
                    });
                }
            };
        }

        var w1Plugin = makeRetentionAnomalyPlugin('retentionAnomaliesW1', 0, w1Anomalies);
        var w2Plugin = makeRetentionAnomalyPlugin('retentionAnomaliesW2', 1, w2Anomalies);

        function initRetentionChart() {
            new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Week-1 retention (%)',
                            data: w1,
                            borderColor: '#f472b6',
                            backgroundColor: 'rgba(244,114,182,0.08)',
                            borderWidth: 2,
                            pointRadius: 3,
                            pointBackgroundColor: '#f472b6',
                            tension: 0.3,
                            spanGaps: true,
                            fill: true,
                        },
                        {
                            label: 'Week-2 retention (%)',
                            data: w2,
                            borderColor: '#a78bfa',
                            borderWidth: 2,
                            pointRadius: 2,
                            pointBackgroundColor: '#a78bfa',
                            tension: 0.3,
                            spanGaps: true,
                            fill: false,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: '#9ca3af', font: { size: 11 } } } },
                    scales: {
                        x: {
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            ticks: { color: '#6b7280', font: { size: 10 }, maxTicksLimit: 10 }
                        },
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            ticks: { color: '#6b7280', font: { size: 10 }, callback: function (v) { return v + '%'; } },
                            title: { display: true, text: '% of cohort active', color: '#6b7280', font: { size: 10 } }
                        }
                    }
                },
                plugins: [].concat(
                    w1Anomalies.length > 0 ? [w1Plugin] : [],
                    w2Anomalies.length > 0 ? [w2Plugin] : []
                )
            });
        }

        waitForChart(30);
    })();
    </script>

    {{-- ITERATION 9 — funnel-stage conversion-rate trend chart. Same
         waitForChart pattern + same inline-plugin architecture as the
         TTFE + retention charts. 4 datasets (one per stage), each with
         its own color from $funnelStageTrend. The anomaly plugin rings
         low stage-rate weeks amber (worse — stage drop) and high weeks
         emerald (better — stage jump), matching the retention chart's
         inverted direction convention (a stage-rate rise is good, same
         as a retention rise).

         Workstream C — per-shape tooltip override plugin. The TTFE
         chart and W1/W2 retention charts above ship their anomaly data
         in JS payload (var anomalies / w1Anomalies / w2Anomalies) but
         only render a static ±Nsigma label on the canvas; the mean /
         sigma_eff / z breakdown is in the payload but not surfaced. The
         tooltip override plugin below is shared across all 3 charts:
         when hovering a ringed point, the tooltip body shows the
         breakdown (mean / sigma_eff / z / direction). The plugin is
         conditional on anomalies.length > 0 so a clean trend renders
         with the default tooltip behavior. --}}
    @if(!empty($funnelStageTrend) && count($onboardingTrend) >= 2)
    <script nonce="@nonce">
    (function () {
        var canvas = document.getElementById('funnel-stage-trend-chart');
        if (!canvas) return;

        var labels = @json(collect($onboardingTrend)->pluck('captured_at'));
        var stages = @json($funnelStageTrend);

        // Build a tooltip override plugin that adds the anomaly
        // breakdown (mean / sigma_eff / z / direction) to the default
        // tooltip when hovering a ringed point. The data lives in each
        // stage's `anomalies` list — the plugin finds the matching
        // anomaly by chart index + datasetIndex and appends the
        // breakdown lines. Mirrors the plugin factory shape the
        // retention chart uses (so a future refactor could unify them).
        function makeFunnelTooltipPlugin() {
            return {
                id: 'funnelAnomalyTooltip',
                afterBody: function (tooltipItems) {
                    if (!tooltipItems || tooltipItems.length === 0) return [];
                    var item = tooltipItems[0];
                    var datasetIndex = item.datasetIndex;
                    var index = item.dataIndex;
                    var stage = stages[datasetIndex];
                    if (!stage || !stage.anomalies) return [];
                    var match = stage.anomalies.find(function (a) { return a.index === index; });
                    if (!match) return [];
                    var dirLabel = match.direction === 'low' ? 'drop (worse)' : 'rise (better)';
                    return [
                        '',
                        'mean: ' + match.mean.toFixed(1) + '%',
                        'sigma_eff: ' + match.sigma_eff.toFixed(2),
                        'z: ' + (match.z > 0 ? '+' : '') + match.z.toFixed(2),
                        'direction: ' + dirLabel,
                    ];
                }
            };
        }

        // Inline plugin — ring anomalous funnel-stage points. Each
        // stage is a separate dataset (datasetIndex = stage order),
        // so the ring sits on the right series. Color INVERTED vs
        // TTFE (low = worse = amber; high = better = emerald), same
        // as the retention chart.
        function makeFunnelAnomalyPlugin(datasetIndex, anomalies) {
            return {
                id: 'funnelAnomalies_' + datasetIndex,
                afterDatasetsDraw: function (chart) {
                    if (!anomalies.length) return;
                    var ctx = chart.ctx;
                    var chartArea = chart.chartArea;
                    var xAxis = chart.scales.x;
                    var meta = chart.getDatasetMeta(datasetIndex);
                    if (!meta || !meta.data) return;

                    anomalies.forEach(function (a) {
                        var x = xAxis.getPixelForValue(a.index);
                        if (x < chartArea.left || x > chartArea.right) return;
                        var pt = meta.data[a.index];
                        if (!pt) return;
                        var y = pt.y;

                        var isLow = a.direction === 'low';
                        var color = isLow ? 'rgba(251,191,36,0.95)' : 'rgba(52,211,153,0.95)';

                        ctx.save();
                        ctx.strokeStyle = color;
                        ctx.lineWidth = 2;
                        ctx.beginPath();
                        ctx.arc(x, y, 7, 0, Math.PI * 2);
                        ctx.stroke();

                        ctx.fillStyle = color;
                        ctx.font = '10px sans-serif';
                        ctx.textAlign = 'center';
                        var sign = isLow ? '-' : '+';
                        var labelY = isLow ? y + 18 : y - 12;
                        ctx.fillText(sign + Math.abs(a.z) + 'sigma', x, labelY);
                        ctx.restore();
                    });
                }
            };
        }

        var anomalyPlugins = stages.map(function (stage, i) {
            return stage.anomalies.length > 0 ? makeFunnelAnomalyPlugin(i, stage.anomalies) : null;
        }).filter(Boolean);

        var hasAnomalies = anomalyPlugins.length > 0;

        function waitForChart(attemptsLeft) {
            if (window.Chart) { initFunnelChart(); return; }
            if (attemptsLeft <= 0) {
                console.error('Chart.js failed to load (admin-vendor.js) — funnel-stage trend not rendered.');
                return;
            }
            setTimeout(function () { waitForChart(attemptsLeft - 1); }, 100);
        }

        function initFunnelChart() {
            var datasets = stages.map(function (stage) {
                return {
                    label: stage.label,
                    data: stage.series,
                    borderColor: stage.color,
                    backgroundColor: stage.color + '20', // 12% alpha fill
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: stage.color,
                    tension: 0.3,
                    spanGaps: true,
                    fill: false,
                };
            });

            var plugins = [].concat(
                anomalyPlugins,
                hasAnomalies ? [makeFunnelTooltipPlugin()] : []
            );

            new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: { labels: labels, datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: '#9ca3af', font: { size: 10 } } } },
                    scales: {
                        x: {
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            ticks: { color: '#6b7280', font: { size: 10 }, maxTicksLimit: 10 }
                        },
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            ticks: { color: '#6b7280', font: { size: 10 }, callback: function (v) { return v + '%'; } },
                            title: { display: true, text: '% conversion', color: '#6b7280', font: { size: 10 } }
                        }
                    }
                },
                plugins: plugins
            });
        }

        waitForChart(30);
    })();
    </script>
    @endif

    {{-- ITERATION 9 (workstream C) — per-shape tooltip override on the TTFE
         + W1/W2 retention anomaly rings. The existing inline <script> blocks
         above already define the ring plugins (anomalyPlugin for TTFE,
         w1Plugin/w2Plugin for retention); the per-shape tooltip override
         below augments the default tooltip when hovering a ringed point so
         the operator can read the mean / sigma_eff / z breakdown without
         hand-recomputing. The plugin is conditional on the anomalies JS
         variable having entries so a clean trend renders with the default
         tooltip behavior. --}}
    @php
        $hasTtfeAnomalies = count($anomalyAnnotations ?? []) > 0;
        $hasRetentionAnomalies = (count($retentionW1Anomalies ?? []) + count($retentionW2Anomalies ?? [])) > 0;
        $showAnomalyTooltipOverride = $hasTtfeAnomalies || $hasRetentionAnomalies;
    @endphp
    @if($showAnomalyTooltipOverride)
    <script nonce="@nonce">
    (function () {
        // Wait for both Chart + the trend canvases to exist (the trend
        // init scripts above are IIFEs that poll for window.Chart too;
        // we attach the tooltip override via Chart.pluginServiceBase so
        // it applies to every chart on the page, then guard inside the
        // hook so only anomaly-bearing points show the breakdown).
        function attachTooltipOverride() {
            if (!window.Chart) return;

            // Per-chart anomaly data lives on each canvas's chart instance
            // (the inline scripts above pass it as the `anomalies` var).
            // Since we can't read another IIFE's closure, we re-derive the
            // anomaly metadata from the JSON payloads the inline scripts
            // embedded — same source the ring plugins read.
            var ttfeAnomalies = @json($anomalyAnnotations ?? []);
            var w1Anomalies = @json($retentionW1Anomalies ?? []);
            var w2Anomalies = @json($retentionW2Anomalies ?? []);

            // Chart.js external tooltip hook. Returns an array of body
            // lines appended after the default tooltip body. The hook is
            // per-chart-instance via the options.plugins.tooltip.external
            // pattern, but registering globally with a guard is simpler
            // and safe — the guard skips any chart instance without
            // matching anomaly data.
            Chart.defaults.plugins.tooltip.external = function (context) {
                var tooltip = context.tooltip;
                if (!tooltip || !tooltip.dataPoints || tooltip.dataPoints.length === 0) return;

                var dp = tooltip.dataPoints[0];
                var chartId = dp.chart && dp.chart.canvas ? dp.chart.canvas.id : null;
                var index = dp.dataIndex;
                var datasetIndex = dp.datasetIndex;

                // Map chart canvas ID → the anomaly list that chart drew.
                var list = null;
                if (chartId === 'ttfe-trend-chart') list = ttfeAnomalies;
                else if (chartId === 'retention-trend-chart') {
                    list = datasetIndex === 0 ? w1Anomalies : (datasetIndex === 1 ? w2Anomalies : null);
                }
                if (!list) return;

                var match = list.find(function (a) { return a.index === index; });
                if (!match) return;

                // Append the breakdown lines below the default tooltip body.
                // Chart.js reads the `afterBody` callback's return as an
                // array of strings; each becomes a new line below the body.
                if (!tooltip.afterBody) tooltip.afterBody = [];
                if (typeof tooltip.afterBody === 'function') return; // already overridden — skip
                var dirLabel = match.direction === 'low' ? 'drop' : 'rise';
                tooltip.afterBody = [
                    '',
                    'mean: ' + Number(match.mean).toFixed(1),
                    'sigma_eff: ' + Number(match.sigma_eff).toFixed(2),
                    'z: ' + (match.z > 0 ? '+' : '') + Number(match.z).toFixed(2),
                    'direction: ' + dirLabel,
                ];
            };
        }

        // Poll for Chart.js (same pattern as the inline scripts above).
        // Once attached, the override persists for the life of the page.
        function waitForChart(attemptsLeft) {
            if (window.Chart) { attachTooltipOverride(); return; }
            if (attemptsLeft <= 0) {
                console.error('Chart.js failed to load (admin-vendor.js) — anomaly tooltip override not attached.');
                return;
            }
            setTimeout(function () { waitForChart(attemptsLeft - 1); }, 100);
        }
        waitForChart(30);
    })();
    </script>
    @endif

    <script nonce="@nonce">
    // (Task H32) Modal openers for type-to-confirm destructive actions
    function openDeleteModal(userId, userName) {
        const modal = document.getElementById('deleteConfirmModal');
        if (modal.__x) {
            modal.__x.$data.open = true;
            modal.__x.$data.userId = userId;
            modal.__x.$data.userName = userName;
            modal.__x.$data.typed = '';
        }
        setTimeout(() => document.getElementById('delete-confirm-input')?.focus(), 100);
    }

    function openAdminModal(userId, userName, action) {
        const modal = document.getElementById('adminConfirmModal');
        if (modal.__x) {
            modal.__x.$data.open = true;
            modal.__x.$data.userId = userId;
            modal.__x.$data.userName = userName;
            modal.__x.$data.action = action;
            modal.__x.$data.typed = '';
        }
        setTimeout(() => document.getElementById('admin-confirm-input')?.focus(), 100);
    }
    </script>

</body>
</html>