@php
    $issues = collect($summary['issues'] ?? [])->take(3);
    $totalIssues = (int) ($summary['total_issues'] ?? 0);
    $totalEvents = (int) ($summary['total_events'] ?? 0);
    $totalUsers = (int) ($summary['total_users'] ?? 0);
    $slug = trim((string) $application->sentry_project_slug);
@endphp

<div class="rounded-lg border border-slate-800 bg-slate-900/40 px-4 py-3">
    <div class="flex items-baseline justify-between gap-3 mb-1">
        <span class="text-sm font-medium text-slate-200">{{ $application->name }}</span>
        <span class="text-xs font-mono text-slate-500">{{ $slug }}</span>
    </div>

    @if(empty($summary['configured']))
        <p class="text-xs text-slate-500" title="SENTRY_API_TOKEN / SENTRY_ORG_SLUG not set — no fetch was attempted">
            Headlines not fetched — the Sentry API token is not configured.
        </p>
    @elseif(! empty($summary['error']))
        <p class="text-xs text-amber-400/90" title="{{ $summary['error'] }}">
            Issue headlines unavailable — {{ \Illuminate\Support\Str::limit($summary['error'], 90) }}
        </p>
    @elseif($issues->isEmpty())
        <p class="text-xs text-emerald-400/80">No unresolved issues in the last 24 h.</p>
    @else
        <p class="text-xs text-slate-500 mb-2">
            {{ number_format($totalIssues) }} unresolved issue{{ $totalIssues === 1 ? '' : 's' }} ·
            {{ number_format($totalEvents) }} event{{ $totalEvents === 1 ? '' : 's' }} ·
            {{ number_format($totalUsers) }} user{{ $totalUsers === 1 ? '' : 's' }} (24 h)
        </p>
        <ul class="space-y-1.5">
            @foreach($issues as $issue)
                <li class="text-xs leading-snug">
                    <a href="{{ $issue['link'] }}" target="_blank" rel="noopener"
                       class="text-slate-300 hover:text-emerald-300 underline decoration-slate-700 underline-offset-2"
                       title="{{ $issue['culprit'] !== '' ? $issue['culprit'] : $issue['title'] }}">
                        {{ \Illuminate\Support\Str::limit($issue['title'], 72) }}
                    </a>
                    <span class="text-slate-500">
                        — {{ number_format((int) $issue['count']) }} event{{ (int) $issue['count'] === 1 ? '' : 's' }}
                        @if((int) $issue['user_count'] > 0) · {{ number_format((int) $issue['user_count']) }} user{{ (int) $issue['user_count'] === 1 ? '' : 's' }} @endif
                    </span>
                </li>
            @endforeach
        </ul>
        @if($totalIssues > $issues->count())
            <p class="text-xs text-slate-600 mt-2">{{ number_format($totalIssues - $issues->count()) }} more in Sentry →</p>
        @endif
    @endif
</div>
