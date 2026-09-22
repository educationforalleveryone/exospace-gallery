<x-app-layout>
    @vite(['resources/js/admin-vendor.js'])

    <x-slot name="header">
        <x-page-header title="Master Control" description="Super-admin control plane — platform statistics, users, and operational tools.">
            <x-slot:meta>
                <nav aria-label="Master Control sections" class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.dashboard') }}" class="btn btn-sm btn-ghost">← Dashboard</a>
                    <a href="{{ route('super.venues.index') }}" class="btn btn-sm btn-ghost">Venue Templates</a>
                    <a href="{{ route('super.featured.index') }}" class="btn btn-sm btn-ghost">Featured</a>
                    <a href="{{ route('super.seo.index') }}" class="btn btn-sm btn-ghost">SEO Operations</a>
                    <a href="{{ route('super.pending-upgrades.index') }}" class="btn btn-sm btn-ghost">Pending Upgrades</a>
                    <a href="{{ route('super.billing.index') }}" class="btn btn-sm btn-ghost">Billing Review</a>
                    <a href="{{ route('super.webhooks.index') }}" class="btn btn-sm btn-ghost">Outbound Webhooks</a>
                    <a href="{{ route('super.feedback.index') }}" class="btn btn-sm btn-ghost">Feedback</a>
                    <a href="{{ route('super.nps.index') }}" class="btn btn-sm btn-ghost">NPS</a>
                    <a href="{{ route('super.affiliates.index') }}" class="btn btn-sm btn-ghost">Affiliates</a>
                </nav>
            </x-slot:meta>
        </x-page-header>
    </x-slot>

    <div class="page-shell">
        {{-- Flash messages --}}

    <!-- Platform Statistics -->
    <div class="pt-6">
        <h2 class="eyebrow mb-4">Platform Statistics</h2>
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
            @php
                $statTones = [
                    'blue'   => 'bg-blue-500/10   border-blue-500/30   text-blue-300',
                    'purple' => 'bg-brand-500/10  border-brand-500/30  text-brand-300',
                    'indigo' => 'bg-indigo-500/10 border-indigo-500/30 text-indigo-300',
                    'pink'   => 'bg-pink-500/10   border-pink-500/30   text-pink-300',
                    'gray'   => 'bg-gray-500/10   border-gray-500/30   text-gray-300',
                    'yellow' => 'bg-amber-500/10  border-amber-500/30  text-amber-300',
                    'red'    => 'bg-red-500/10    border-red-500/30    text-red-300',
                    'orange' => 'bg-orange-500/10 border-orange-500/30 text-orange-300',
                ];
            @endphp
            <div class="bg-gray-800/60 border rounded-xl p-4 text-center {{ $statTones[$stat['color']] ?? $statTones['gray'] }}">
                <div class="text-2xl font-semibold text-numeric">{{ $stat['val'] }}</div>
                <div class="text-xs text-gray-400 mt-0.5">{{ $stat['label'] }}</div>
            </div>
            @endforeach
        </div>

        @if(($backupHealth['show'] ?? false) && !empty($backupHealth['types']))
            @php
                $backupColors = [
                    'fresh'   => ['border' => 'border-emerald-500/40', 'bg' => 'bg-emerald-900/30', 'text' => 'text-emerald-300', 'icon' => '✅', 'label' => 'all fresh', 'state' => 'healthy'],
                    'stale'   => ['border' => 'border-amber-500/40',   'bg' => 'bg-amber-900/30',   'text' => 'text-amber-300',   'icon' => '⚠️', 'label' => 'one stale', 'state' => 'warning'],
                    'missing' => ['border' => 'border-red-500/40',      'bg' => 'bg-red-900/30',      'text' => 'text-red-300',      'icon' => '🚨', 'label' => 'one missing', 'state' => 'critical'],
                ];
                $bh = $backupHealth['worst'];
                $bc = $backupColors[$bh];
            @endphp
            <div class="mb-8 {{ $bc['bg'] }} border {{ $bc['border'] }} rounded-lg p-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">{{ $bc['icon'] }}</span>
                        <div>
                            <h3 class="text-sm font-semibold {{ $bc['text'] }} uppercase tracking-wider">Backup health — {{ $bc['label'] }}</h3>
                            <p class="text-xs text-gray-500 mt-0.5">Heartbeats stamped by the <code>exospace:backup</code> wrapper. Per-type status below; Slack alerts fire on failure (critical for db/files, warning for clean).</p>
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
                                <x-status-badge :state="$tc['state'] ?? 'unknown'" :label="$type['status']" />
                            </div>
                            <div class="text-gray-500">last run: <span class="text-gray-400">{{ $lastLabel }}</span></div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <details class="mb-8 card card-pad">
            <summary class="eyebrow cursor-pointer select-none">Feature Flags</summary>
            <div class="flex flex-wrap gap-2 mt-3">
                @php $flags = \App\Services\FeatureFlag::all(); @endphp
                @foreach($flags as $name => $enabled)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium
                                 {{ $enabled ? 'bg-emerald-900/40 text-emerald-300 border border-emerald-700/30' : 'bg-gray-800 text-gray-500 border border-gray-700' }}">
                        @if($enabled)
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                        @else
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                        @endif
                        {{ $name }}
                    </span>
                @endforeach
            </div>
        </details>

        <div class="mb-8 card card-pad">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                <h3 class="eyebrow">Onboarding Funnel &amp; TTFE</h3>
                <div class="flex gap-1">
                    @foreach([7, 30, 90] as $period)
                        <a href="{{ route('super.index', ['days' => $period]) }}"
                           class="px-3 py-1 rounded-md text-xs font-medium transition-colors duration-150 {{ $onboardingDays === $period ? 'bg-brand-600 text-white' : 'bg-white/[0.06] text-gray-400 hover:bg-white/[0.10] hover:text-gray-200' }}">
                            {{ $period }}d
                        </a>
                    @endforeach
                </div>
            </div>

            @php
                $funnel = [
                    ['label' => 'Registered',       'value' => $onboarding['registered'],      'color' => 'bg-blue-500'],
                    ['label' => 'Created gallery',  'value' => $onboarding['created_gallery'], 'color' => 'bg-brand-500'],
                    ['label' => 'Uploaded image',   'value' => $onboarding['uploaded_image'],  'color' => 'bg-brand-400'],
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

            <div class="bg-black/40 border border-gray-700/50 rounded-lg p-3 mt-3">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wider">TTFE / TTFG trend — weekly snapshots ({{ $onboardingDays }}d window)</div>
                    <div class="text-xs text-gray-600">{{ count($onboardingTrend) }} point{{ count($onboardingTrend) === 1 ? '' : 's' }} recorded{{ count($releaseAnnotations) > 0 ? ' · ' . count($releaseAnnotations) . ' release marker' . (count($releaseAnnotations) === 1 ? '' : 's') : '' }}{{ count($anomalyAnnotations ?? []) > 0 ? ' · ' . count($anomalyAnnotations) . ' anomal' . (count($anomalyAnnotations) === 1 ? 'y' : 'ies') : '' }}</div>
                </div>
                @if(count($onboardingTrend) >= 2)
                    @php
                        $ttfePoints = count($onboardingTrend);
                        $ttfeReleases = count($releaseAnnotations);
                        $ttfeAnomalies = count($anomalyAnnotations ?? []);
                        $ttfeAria = "TTFE trend chart, {$ttfePoints} weekly snapshot" . ($ttfePoints === 1 ? '' : 's');
                        if ($ttfeReleases > 0) { $ttfeAria .= ", {$ttfeReleases} release marker" . ($ttfeReleases === 1 ? '' : 's'); }
                        if ($ttfeAnomalies > 0) { $ttfeAria .= ", {$ttfeAnomalies} anomal" . ($ttfeAnomalies === 1 ? 'y' : 'ies'); }
                    @endphp
                    <div class="h-56"><canvas id="ttfe-trend-chart" role="img" aria-label="{{ $ttfeAria }}"></canvas></div>
                    <div class="hint-text mt-1">Average hours from signup to first gallery (TTFG) and first published exhibition (TTFE). Lower is better. Dashed lines mark releases (from the /changelog calendar). Amber/emerald rings mark weeks that deviate >2σ from the trailing mean.</div>
                @else
                    <div class="text-sm text-gray-500 py-6 text-center">
                        Trend appears after the second weekly snapshot — the first is already recorded and will chart next Monday.
                    </div>
                @endif
            </div>

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
                        <div class="text-xs text-gray-600">{{ $fsPoints }} point{{ $fsPoints === 1 ? '' : 's' }} recorded · 4 stages{{ $fsAnomalyTotal > 0 ? ' · ' . $fsAnomalyTotal . ' anomal' . ($fsAnomalyTotal === 1 ? 'y' : 'ies') : '' }}</div>
                    </div>
                    <div class="h-56"><canvas id="funnel-stage-trend-chart" role="img" aria-label="{{ $fsAria }}"></canvas></div>
                    <div class="hint-text mt-1">Conversion rate per funnel stage, weekly. Higher is better. Amber rings mark weeks a stage rate drops >2σ below the trailing mean (worse — stage drop); emerald rings mark weeks a stage rate rises >2σ above (better).</div>
                </div>
            @endif
        </div>

        <div class="mb-8 card card-pad">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                <h3 class="eyebrow">🔁 Weekly cohort retention</h3>
                <div class="text-xs text-gray-600">active = login or gallery update in the week · * = week not closed yet</div>
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
                        @forelse($retention['cohorts'] as $cohort)
                            <tr class="border-t border-gray-800/60">
                                <td class="py-1.5 pr-3 text-gray-300 whitespace-nowrap">{{ $cohort['label'] }}</td>
                                <td class="py-1.5 px-2 text-right text-gray-400">{{ number_format($cohort['size']) }}</td>
                                @foreach($cohort['cells'] as $w => $cell)
                                    @php
                                        $pct = (float) $cell['pct'];
                                        $shade = $pct >= 40 ? 'bg-emerald-900/60 text-emerald-200'
                                                  : ($pct >= 20 ? 'bg-emerald-900/30 text-emerald-300'
                                                  : ($pct >= 10 ? 'bg-amber-900/30 text-amber-300' : 'text-gray-600'));
                                        if (! $cell['complete']) { $shade .= ' opacity-50'; }
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
                        @empty
                            <tr class="border-t border-gray-800/60">
                                <td colspan="{{ 2 + $retention['weeks'] }}" class="py-6 text-center text-gray-500">
                                    <p class="text-xs font-medium text-gray-400">No cohort data yet</p>
                                    <p class="text-xs mt-1">Cohorts appear once the first users register. Check back after your first week of signups.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-black/40 border border-gray-700/50 rounded-lg p-3 mt-3">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wider">Week-1 / Week-2 retention trend — weekly snapshots</div>
                    <div class="text-xs text-gray-600">{{ count($retentionTrendW1) }} point{{ count($retentionTrendW1) === 1 ? '' : 's' }} recorded{{ (count($retentionW1Anomalies ?? []) + count($retentionW2Anomalies ?? [])) > 0 ? ' · ' . (count($retentionW1Anomalies ?? []) + count($retentionW2Anomalies ?? [])) . ' anomal' . ((count($retentionW1Anomalies ?? []) + count($retentionW2Anomalies ?? [])) === 1 ? 'y' : 'ies') : '' }}</div>
                </div>
                @if(count($retentionTrendW1) >= 2)
                    @php
                        $retPoints = count($retentionTrendW1);
                        $retAnomalies = count($retentionW1Anomalies ?? []) + count($retentionW2Anomalies ?? []);
                        $retAria = "Week-1 and Week-2 retention trend chart, {$retPoints} weekly snapshot" . ($retPoints === 1 ? '' : 's');
                        if ($retAnomalies > 0) { $retAria .= ", {$retAnomalies} anomal" . ($retAnomalies === 1 ? 'y' : 'ies'); }
                    @endphp
                    <div class="h-56"><canvas id="retention-trend-chart" role="img" aria-label="{{ $retAria }}"></canvas></div>
                    <div class="hint-text mt-1">% of each cohort active (login or gallery update) in their 1st / 2nd week after registration. Higher is better. Amber rings mark weeks that drop >2σ below the trailing mean (churn up); emerald rings mark weeks that rise >2σ above.</div>
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
                   class="input-base flex-1">
            <select aria-label="Filter users by plan" id="planFilter" class="input-base sm:w-40">
                <option value="">All Plans</option>
                <option value="free">Free</option>
                <option value="pro">Pro</option>
                <option value="studio">Studio</option>
            </select>
            <select aria-label="Filter users by status" id="statusFilter" class="input-base sm:w-44">
                <option value="">All Status</option>
                <option value="banned">Banned</option>
                <option value="unverified">Unverified</option>
                <option value="verified">Verified</option>
            </select>
        </div>

        <!-- Users Table -->
        <h2 class="eyebrow mb-4">All Users</h2>
        <div class="table-wrap">
            <table class="table-base min-w-[880px]" id="usersTable">
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
                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-brand-500 to-pink-500 flex items-center justify-center font-bold text-sm flex-shrink-0">
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
                                    <div class="text-xs text-gray-400 break-all">{{ $user->email }}</div>
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
                                    <span class="text-xs bg-emerald-900/40 border border-emerald-700/30 text-emerald-400 px-2 py-0.5 rounded-full w-fit">✓ Verified</span>
                                @else
                                    <span class="text-xs bg-amber-900/40 border border-amber-700/30 text-amber-400 px-2 py-0.5 rounded-full w-fit">⚠ Unverified</span>
                                @endif
                            </div>
                        </td>

                        {{-- Plan --}}
                        <td class="px-5 py-4">
                            @if(! $isSelf)
                            <form method="POST" action="{{ route('super.updatePlan', $user) }}">
                                @csrf
                                <select aria-label="Change plan" name="plan" data-change="confirmChangePlan" data-arg="Change plan for {{ $user->name }}?"
                                        class="input-sm input-base">
                                    <option value="free"   {{ $user->plan === 'free'   ? 'selected' : '' }}>FREE</option>
                                    <option value="pro"    {{ $user->plan === 'pro'    ? 'selected' : '' }}>PRO</option>
                                    <option value="studio" {{ $user->plan === 'studio' ? 'selected' : '' }}>STUDIO</option>
                                </select>
                            </form>
                            @else
                                <span class="badge {{ $user->plan === 'free' ? 'badge-neutral' : '' }} {{ $user->plan === 'pro' ? 'badge-brand' : '' }} {{ $user->plan === 'studio' ? 'badge-info' : '' }}">
                                    {{ strtoupper($user->plan) }}
                                </span>
                            @endif
                        </td>

                        {{-- Galleries --}}
                        <td class="px-5 py-4">
                            <a href="{{ route('super.user-galleries', $user) }}" class="text-brand-400 hover:text-brand-300 text-sm transition">
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
                                                class="btn btn-sm btn-secondary">
                                            Unban
                                        </button>
                                    </form>
                                @else
                                    <button data-click="openBanModal" data-args="{{ json_encode([$user->id, $user->name]) }}"
                                            class="btn btn-sm btn-danger-ghost">
                                        Ban
                                    </button>
                                @endif

                                {{-- Verify / Unverify email --}}
                                @if(! $isVerified)
                                    <form method="POST" action="{{ route('super.verifyEmail', $user) }}">
                                        @csrf
                                        <button type="submit"
                                                data-confirm-click="Manually verify email for {{ $user->name }}?"
                                                class="btn btn-sm btn-secondary">
                                            Verify
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('super.unverifyEmail', $user) }}">
                                        @csrf
                                        <button type="submit"
                                                data-confirm-click="Revoke email verification for {{ $user->name }}? They will need to verify again."
                                                class="btn btn-sm btn-secondary">
                                            Unverify
                                        </button>
                                    </form>
                                @endif

                                {{-- Toggle Super Admin --}}
                                @if(! $user->is_super_admin)
                                    <button type="button"
                                            data-click="openAdminModal" data-args="{{ json_encode([$user->id, $user->name, 'grant']) }}"
                                            class="btn btn-sm btn-secondary">
                                        Make Admin
                                    </button>
                                @else
                                    <button type="button"
                                            data-click="openAdminModal" data-args="{{ json_encode([$user->id, $user->name, 'revoke']) }}"
                                            class="btn btn-sm btn-danger-ghost">
                                        Revoke Admin
                                    </button>
                                @endif

                                {{-- Impersonate (Login As User) --}}
                                @featureFlag('admin_impersonation')
                                @if(! $user->is_super_admin)
                                    <form method="POST" action="{{ route('super.impersonate', $user) }}">
                                        @csrf
                                        <button type="submit"
                                                data-confirm-click="Log in as {{ $user->name }}? You will see the site from their perspective. Click &quot;Return to admin&quot; to stop."
                                                class="btn btn-sm btn-secondary">
                                            Login As
                                        </button>
                                    </form>
                                @endif
                                @endfeatureFlag

                                {{-- Delete --}}
                                @if(! $user->is_super_admin)
                                    <button type="button"
                                            data-click="openDeleteModal" data-args="{{ json_encode([$user->id, $user->name]) }}"
                                            class="btn btn-sm btn-danger">
                                        Delete
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
                    @if($users->isEmpty())
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center">
                                <div class="empty-state">
                                    <svg class="w-10 h-10 text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    <p class="text-gray-300 text-sm font-medium">No users yet</p>
                                    <p class="text-gray-500 text-xs mt-1">Users appear here as soon as they register.</p>
                                </div>
                            </td>
                        </tr>
                    @endif
                    <tr id="usersNoResults" class="hidden">
                        <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-500">
                            No users match the current search / filters.
                            <button type="button" id="usersResetFilters" class="action-link ms-2">Reset filters</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Ban Modal -->
    <div id="banModal" role="dialog" aria-modal="true" aria-labelledby="ban-modal-title"
         class="fixed inset-0 bg-black/70 backdrop-blur-sm z-[60] hidden items-center justify-center overflow-y-auto px-4">
        <div class="bg-gray-800 border border-red-700/50 rounded-xl p-6 w-full max-w-md shadow-modal">
            <h3 id="ban-modal-title" class="modal-title mb-1">Ban User</h3>
            <p class="text-gray-400 text-sm mb-4">Banning <strong id="banUserName" class="text-white"></strong>. They will be blocked from logging in.</p>
            <form id="banForm" method="POST">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm text-gray-400 mb-1.5">Reason <span class="text-gray-600">(optional)</span></label>
                    <textarea name="reason" rows="3" placeholder="e.g. Violation of terms of service"
                              class="input-base resize-none"></textarea>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="btn btn-danger flex-1">
                        Confirm Ban
                    </button>
                    <button type="button" data-click="closeBanModal" class="btn btn-secondary">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="@nonce">

        // CSP-safe delegated change handler: styled confirm + guarded submit
        window.confirmChangePlan = function(message, e) {
            window.exospaceConfirm(e, message);
        };

        function openBanModal(userId, userName) {
            document.getElementById('banUserName').textContent = userName;
            document.getElementById('banForm').action = '/master-control/users/' + userId + '/ban';
            openModal('banModal');
        }
        function closeBanModal() {
            closeModal('banModal');
        }

        // Search & filter
        const search     = document.getElementById('userSearch');
        const planFilter = document.getElementById('planFilter');
        const statusFilter = document.getElementById('statusFilter');

        var applyFilters = function() {
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

        const noResultsRow = document.getElementById('usersNoResults');
        const baseApplyFilters = applyFilters;
        applyFilters = function() {
            baseApplyFilters();
            if (noResultsRow) {
                const anyVisible = [...document.querySelectorAll('.user-row')]
                    .some((row) => row.style.display !== 'none');
                noResultsRow.classList.toggle('hidden', anyVisible);
            }
        };

        search.addEventListener('input', applyFilters);
        planFilter.addEventListener('change', applyFilters);
        statusFilter.addEventListener('change', applyFilters);

        const resetBtn = document.getElementById('usersResetFilters');
        if (resetBtn) resetBtn.addEventListener('click', () => {
            search.value = ''; planFilter.value = ''; statusFilter.value = '';
            applyFilters();
        });
    </script>


    {{-- Type-to-confirm modals for destructive super-admin actions --}}
    <div id="deleteConfirmModal" x-data="{ open: false, typed: '', userId: 0, userName: '' }"
         x-cloak
         x-effect="document.body.classList.toggle('overflow-y-hidden', open)"
         data-focus-trap
         class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[60] hidden items-center justify-center overflow-y-auto p-4"
         role="dialog" aria-modal="true" aria-labelledby="delete-modal-heading"
         :class="open ? 'flex' : 'hidden'"
         @keydown.escape.window="open = false; typed = ''"
         @click.self="open = false; typed = ''">
        <div class="bg-gray-800 border border-red-700/50 rounded-xl max-w-md w-full shadow-modal p-6 relative">
            <button @click="open = false; typed = ''" class="modal-close absolute top-3 right-3" aria-label="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <h3 id="delete-modal-heading" class="modal-title text-red-400 mb-3">Permanently Delete User</h3>
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
                    class="input-base"
                    autocomplete="off">
            </div>
            <form :action="'/master-control/users/' + userId" method="POST" id="deleteForm">
                @csrf
                <input type="hidden" name="_method" value="DELETE">
                <div class="flex gap-3">
                    <button type="button" @click="open = false; typed = ''"
                            class="btn btn-secondary flex-1">Cancel</button>
                    <button type="submit" :disabled="typed !== 'DELETE'"
                            class="btn btn-danger flex-1">Delete User</button>
                </div>
            </form>
        </div>
    </div>

    <div id="adminConfirmModal" x-data="{ open: false, typed: '', userId: 0, userName: '', action: 'grant' }"
         x-cloak
         x-effect="document.body.classList.toggle('overflow-y-hidden', open)"
         data-focus-trap
         class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[60] hidden items-center justify-center overflow-y-auto p-4"
         role="dialog" aria-modal="true" aria-labelledby="admin-modal-heading"
         :class="open ? 'flex' : 'hidden'"
         @keydown.escape.window="open = false; typed = ''"
         @click.self="open = false; typed = ''">
        <div class="modal-panel bg-gray-900 border-brand-700/50 max-w-md p-6 relative">
            <button @click="open = false; typed = ''" class="modal-close absolute top-3 right-3" aria-label="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <h3 id="admin-modal-heading" class="modal-title text-brand-400 mb-3"
                x-text="action === 'grant' ? 'Grant Super Admin' : 'Revoke Super Admin'"></h3>
            <div class="text-sm text-gray-400 mb-4 space-y-2">
                <p x-show="action === 'grant'">
                    You are about to grant <strong class="text-brand-400">super admin access</strong> to <strong x-text="userName" class="text-white"></strong>.
                    They will have full platform access including the ability to delete users, change plans, and modify any gallery.
                </p>
                <p x-show="action === 'revoke'">
                    You are about to <strong class="text-brand-400">revoke super admin access</strong> from <strong x-text="userName" class="text-white"></strong>.
                    They will lose access to /master-control/* immediately.
                </p>
            </div>
            <div class="bg-gray-800/50 rounded-lg p-3 mb-4">
                <label for="admin-confirm-input" class="block text-xs text-gray-500 mb-1">
                    Type <code class="text-gray-300 font-mono" x-text="action === 'grant' ? 'GRANT' : 'REVOKE'"></code> to confirm
                </label>
                <input id="admin-confirm-input" type="text" x-model="typed"
                    class="input-base"
                    autocomplete="off">
            </div>
            <form :action="'/master-control/users/' + userId + '/toggle-super-admin'" method="POST" id="adminForm">
                @csrf
                <div class="flex gap-3">
                    <button type="button" @click="open = false; typed = ''"
                            class="btn btn-secondary flex-1">Cancel</button>
                    <button type="submit"
                            :disabled="(action === 'grant' && typed !== 'GRANT') || (action === 'revoke' && typed !== 'REVOKE')"
                            class="btn btn-primary flex-1"
                            x-text="action === 'grant' ? 'Grant Access' : 'Revoke Access'"></button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="@nonce">
    (function () {
        var canvas = document.getElementById('ttfe-trend-chart');
        if (!canvas) return; // fewer than 2 snapshots — placeholder shown

        var labels = @json(collect($onboardingTrend)->pluck('captured_at'));
        var captureDates = @json(collect($onboardingTrend)->pluck('captured_on'));
        var ttfe = @json(collect($onboardingTrend)->pluck('ttfe_avg'));
        var ttfg = @json(collect($onboardingTrend)->pluck('ttfg_avg'));
        var releases = @json($releaseAnnotations);
        var anomalies = @json($anomalyAnnotations ?? []);

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
                plugins: [].concat(
                    releaseMarks.length > 0 ? [releaseAnnotationPlugin] : [],
                    anomalies.length > 0 ? [anomalyPlugin] : []
                )
            });
        }

        waitForChart(30);
    })();

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

        function makeRetentionAnomalyPlugin(id, datasetIndex, anomalies) {
            return {
                id: id,
                afterDatasetsDraw: function (chart) {
                    if (!anomalies.length) return;
                    var ctx = chart.ctx;
                    var chartArea = chart.chartArea;
                    var xAxis = chart.scales.x;
                    var yAxis = chart.scales.y;
                    var meta = chart.getDatasetMeta(datasetIndex);
                    if (!meta || !meta.data) return;

                    anomalies.forEach(function (a) {
                        var x = xAxis.getPixelForValue(a.index);
                        if (x < chartArea.left || x > chartArea.right) return;
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

    @if(!empty($funnelStageTrend) && count($onboardingTrend) >= 2)
    <script nonce="@nonce">
    (function () {
        var canvas = document.getElementById('funnel-stage-trend-chart');
        if (!canvas) return;

        var labels = @json(collect($onboardingTrend)->pluck('captured_at'));
        var stages = @json($funnelStageTrend);

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

    @php
        $hasTtfeAnomalies = count($anomalyAnnotations ?? []) > 0;
        $hasRetentionAnomalies = (count($retentionW1Anomalies ?? []) + count($retentionW2Anomalies ?? [])) > 0;
        $showAnomalyTooltipOverride = $hasTtfeAnomalies || $hasRetentionAnomalies;
    @endphp
    @if($showAnomalyTooltipOverride)
    <script nonce="@nonce">
    (function () {
        function attachTooltipOverride() {
            if (!window.Chart) return;

            var ttfeAnomalies = @json($anomalyAnnotations ?? []);
            var w1Anomalies = @json($retentionW1Anomalies ?? []);
            var w2Anomalies = @json($retentionW2Anomalies ?? []);

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
    function openDeleteModal(userId, userName) {
        const modal = document.getElementById('deleteConfirmModal');
        if (window.Alpine) {
            const data = Alpine.$data(modal);
            data.open = true;
            data.userId = userId;
            data.userName = userName;
            data.typed = '';
        }
        setTimeout(() => document.getElementById('delete-confirm-input')?.focus(), 100);
    }

    function openAdminModal(userId, userName, action) {
        const modal = document.getElementById('adminConfirmModal');
        if (window.Alpine) {
            const data = Alpine.$data(modal);
            data.open = true;
            data.userId = userId;
            data.userName = userName;
            data.action = action;
            data.typed = '';
        }
        setTimeout(() => document.getElementById('admin-confirm-input')?.focus(), 100);
    }
    </script>
    </div><!-- /.page-shell -->
</x-app-layout>