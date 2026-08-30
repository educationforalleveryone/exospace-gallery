{{-- OpsCenter (Iteration 4): the three overview tiles — backups, the 2Checkout
     webhook ledger, and the Sentry bridge. Read-only facts + link-outs to the
     surfaces that own the actions (Master Control backup tile, OpsCenter
     Actions hub for replays, Sentry itself for stack traces). --}}

@php
    $chip = [
        'healthy'  => 'bg-emerald-950/60 text-emerald-300 border-emerald-700/50',
        'degraded' => 'bg-amber-950/60 text-amber-300 border-amber-700/50',
        'critical' => 'bg-red-950/60 text-red-300 border-red-700/50',
        'unknown'  => 'bg-slate-800/60 text-slate-300 border-slate-600/50',
    ];
    $diskLabel = [
        'ok'        => ['Fresh',   'text-emerald-300'],
        'stale'     => ['Stale',   'text-red-300'],
        'missing'   => ['Missing', 'text-red-300'],
        'unreadable'=> ['Unknown', 'text-slate-400'],
    ];
    $levelStyles = [
        'fatal'    => 'text-red-300',
        'error'    => 'text-red-300',
        'warning'  => 'text-amber-300',
        'info'     => 'text-slate-300',
        'debug'    => 'text-slate-500',
    ];
@endphp

{{-- ── Backups tile ─────────────────────────────────────────────────────── --}}
<div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4">
    <div class="flex items-center justify-between gap-2 mb-3">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Backups</h2>
        <span class="text-xs font-semibold px-2 py-1 rounded border {{ $chip[$backupTile['status']] ?? $chip['unknown'] }}">{{ strtoupper($backupTile['status']) }}</span>
    </div>

    @if(empty($backupTile['disks']))
        <p class="text-xs text-slate-500">No backup disks configured (BACKUP_DISKS).</p>
    @else
        <ul class="space-y-2">
            @foreach($backupTile['disks'] as $disk)
                @php $label = $diskLabel[$disk['status']] ?? ['Unknown', 'text-slate-400']; @endphp
                <li class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="text-xs text-slate-200 font-medium">
                            {{ $disk['disk'] }}
                            <span class="ml-1 {{ $label[1] }} font-semibold">{{ $label[0] }}</span>
                        </div>
                        @if($disk['newest_name'] !== null)
                            <div class="text-xs text-slate-500 font-mono truncate">
                                {{ $disk['newest_name'] }} · {{ $disk['newest_age_hours'] }}h old
                                @if($disk['newest_size'] !== null) · {{ number_format($disk['newest_size'] / 1048576, 1) }} MB @endif
                            </div>
                        @else
                            <div class="text-xs text-slate-500">no archives found</div>
                        @endif
                    </div>
                    <span class="text-xs text-slate-500 shrink-0">{{ $disk['file_count'] }} file{{ $disk['file_count'] === 1 ? '' : 's' }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="text-xs text-slate-600 mt-3">
        Freshness threshold: 26 h (same as the alerting service).
        @if(auth()->user()?->is_super_admin)
            <a href="{{ route('super.index') }}" class="text-emerald-400 hover:text-emerald-300">Backup status &amp; runs live in Master Control →</a>
        @endif
    </p>
</div>

{{-- ── Webhooks tile ────────────────────────────────────────────────────── --}}
<div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4">
    <div class="flex items-center justify-between gap-2 mb-3">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Billing Webhooks</h2>
        <span class="text-xs font-semibold px-2 py-1 rounded border {{ $chip[$webhookTile['status']] ?? $chip['unknown'] }}">{{ strtoupper($webhookTile['status']) }}</span>
    </div>

    <div class="grid grid-cols-2 gap-2 mb-2">
        <div class="rounded bg-slate-950/60 border border-slate-800 px-3 py-2 text-center">
            <div class="text-lg font-bold {{ $webhookTile['failed_count'] > 0 ? 'text-red-400' : 'text-emerald-400' }}">{{ $webhookTile['failed_count'] }}</div>
            <div class="text-xs uppercase tracking-wider text-slate-500">Failed</div>
        </div>
        <div class="rounded bg-slate-950/60 border border-slate-800 px-3 py-2 text-center">
            <div class="text-lg font-bold text-slate-300">{{ $webhookTile['processed_24h'] }}</div>
            <div class="text-xs uppercase tracking-wider text-slate-500">Processed 24h</div>
        </div>
    </div>

    <p class="text-xs text-slate-500">
        @if($webhookTile['failed_count'] > 0)
            Oldest failure {{ $webhookTile['oldest_failed_age_hours'] !== null ? $webhookTile['oldest_failed_age_hours'].'h ago' : '—' }}.
            @if(auth()->user()?->is_super_admin)
                <a href="{{ route('ops.actions.index') }}" class="text-amber-300 hover:text-amber-200">Replay from the Actions hub →</a>
            @endif
        @else
            2Checkout IPN ledger clean — every billing event processed.
        @endif
    </p>
</div>

{{-- ── Sentry tile ──────────────────────────────────────────────────────── --}}
<div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4">
    <div class="flex items-center justify-between gap-2 mb-3">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Sentry — Unresolved</h2>
        @if(! empty($sentryTile['error']))
            <span class="text-xs font-semibold px-2 py-1 rounded border {{ $chip['degraded'] }}">API ERROR</span>
        @elseif(($sentryTile['total_issues'] ?? 0) > 0)
            <span class="text-xs font-semibold px-2 py-1 rounded border {{ $chip['degraded'] }}">{{ $sentryTile['total_issues'] }} ISSUE{{ $sentryTile['total_issues'] === 1 ? '' : 'S' }}</span>
        @else
            <span class="text-xs font-semibold px-2 py-1 rounded border {{ $chip['healthy'] }}">QUIET</span>
        @endif
    </div>

    @if(empty($sentryTile['configured']))
        <p class="text-xs text-slate-500">
            Sentry API summary not configured. Error reporting via SENTRY_LARAVEL_DSN keeps working — add
            <span class="font-mono text-slate-400">SENTRY_API_TOKEN</span> + <span class="font-mono text-slate-400">SENTRY_ORG_SLUG</span>
            to also see Sentry's unresolved issues here (master manual §2).
        </p>
    @elseif(! empty($sentryTile['error']))
        <p class="text-xs text-amber-300">{{ $sentryTile['error'] }}</p>
        <p class="text-xs text-slate-600 mt-1">Cached — the dashboard retries on the next TTL window ({{ config('ops.sentry.cache_minutes', 10) }} min). Local error tracking is unaffected.</p>
    @elseif(empty($sentryTile['issues']) || count($sentryTile['issues']) === 0)
        <p class="text-xs text-slate-500">No unresolved Sentry issues in the last 24 hours.</p>
    @else
        <p class="text-xs text-slate-500 mb-2">
            {{ $sentryTile['total_issues'] }} issue(s) · {{ $sentryTile['total_events'] }} events · ~{{ $sentryTile['total_users'] }} users affected (24 h)
        </p>
        <ul class="space-y-2">
            @foreach($sentryTile['issues'] as $issue)
                <li>
                    <a href="{{ $issue['link'] }}" target="_blank" rel="noopener" class="text-xs text-slate-200 hover:text-emerald-300">
                        <span class="{{ $levelStyles[$issue['level']] ?? 'text-slate-300' }}">{{ strtoupper($issue['level']) }}</span>
                        {{ \Illuminate\Support\Str::limit($issue['title'], 64) }}
                        <span class="text-slate-600">↗</span>
                    </a>
                    <div class="text-xs text-slate-600 mt-0.5">
                        {{ $issue['count'] }}× · ~{{ $issue['user_count'] }} users
                        @if($issue['culprit'] !== '') · <span class="font-mono">{{ \Illuminate\Support\Str::limit($issue['culprit'], 40) }}</span>@endif
                        @if($issue['project'] !== '') · {{ $issue['project'] }}@endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Iteration 6: the 24-hour error-volume sparkline (pure SVG — no JS,
         no npm). Renders whenever the trend endpoint answered, including
         the all-quiet case (a flat baseline is information too). --}}
    @include('ops.partials.sentry-trend')

    @if(! empty($sentryTile['configured']))
        <p class="text-xs text-slate-600 mt-3">
            Headlines only — stack traces and release tagging live in
            <a href="{{ app(\App\Ops\Services\SentryApiClient::class)->issuesUrl() }}" target="_blank" rel="noopener" class="text-emerald-400 hover:text-emerald-300">Sentry ↗</a>
        </p>
    @endif
</div>
