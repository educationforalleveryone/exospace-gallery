@extends('ops.layout')

@section('title', 'Morning Digest')

@section('content')
<div class="flex flex-wrap items-start justify-between gap-4 mb-4">
    <div>
        <h1 class="text-xl font-semibold">Morning Digest</h1>
        <p class="text-xs text-slate-400 mt-1">
            One Slack message a day (08:15) that unifies everything the control plane watches.
            This preview renders the <em>exact</em> message the scheduled task sends — same composer, same renderer, no drift.
        </p>
    </div>
    <div class="flex flex-col items-end gap-2">
        @if(auth()->user()?->is_super_admin)
            <form method="POST" action="{{ route('ops.digest.send') }}">
                @csrf
                <button class="px-4 py-2 rounded-lg bg-emerald-700/80 hover:bg-emerald-600 text-xs font-medium text-slate-50 transition">
                    Send now
                </button>
            </form>
            <span class="text-xs text-slate-500">Bypasses the daily dedup — for testing.</span>
        @endif
    </div>
</div>

{{-- ── Meta strip ─────────────────────────────────────────────────────── --}}
<div class="grid sm:grid-cols-3 gap-3 mb-6">
    <div class="rounded-lg border border-slate-800 bg-slate-900/40 px-4 py-3">
        <div class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-1">Next scheduled</div>
        <div class="text-sm text-slate-200">Daily at 08:15</div>
        <div class="text-xs text-slate-500 mt-0.5">after the nightly batch, before the 09:00 credential reminder</div>
    </div>
    <div class="rounded-lg border border-slate-800 bg-slate-900/40 px-4 py-3">
        <div class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-1">Last sent</div>
        @if($lastSent)
            <div class="text-sm text-slate-200">{{ $lastSent['at']->diffForHumans() }}</div>
            <div class="text-xs text-slate-500 mt-0.5">trigger: {{ $lastSent['trigger'] }} · {{ $lastSent['at']->format('Y-m-d H:i') }}</div>
        @else
            <div class="text-sm text-slate-400">Not sent yet</div>
            <div class="text-xs text-slate-500 mt-0.5">the first delivery lands at the next 08:15 run</div>
        @endif
    </div>
    <div class="rounded-lg border {{ $enabled ? 'border-slate-800 bg-slate-900/40' : 'border-amber-800/50 bg-amber-950/30' }} px-4 py-3">
        <div class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-1">Scheduled send</div>
        <div class="text-sm {{ $enabled ? 'text-slate-200' : 'text-amber-300' }}">{{ $enabled ? 'Enabled' : 'Disabled' }}</div>
        <div class="text-xs text-slate-500 mt-0.5">
            {{ $enabled ? 'OPS_MORNING_DIGEST_ENABLED (default)' : 'OPS_MORNING_DIGEST_ENABLED=false — the preview and Send now still work' }}
        </div>
    </div>
</div>

@if(! $enabled)
<div class="mb-5 rounded-lg border border-amber-800/50 bg-amber-950/30 px-4 py-3 text-sm text-amber-200">
    The daily send is switched off. The silence contract (§16.4) is suspended: while disabled, an absent morning
    message means nothing either way — re-enable with <span class="font-mono text-xs">OPS_MORNING_DIGEST_ENABLED=true</span>.
</div>
@endif

@if($digest === null)
<div class="rounded-lg border border-red-800/50 bg-red-950/30 px-4 py-6 text-center text-red-300 text-sm">
    The digest could not be composed — a data source failed before the per-section guards could catch it.
    This is a bug worth investigating in the Laravel log; the scheduled send would report the same.
</div>
@else
    {{-- ── Section cards ─────────────────────────────────────────────── --}}
    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4 mb-8">
        @foreach($digest['sections'] as $section)
            @php
                $statusStyles = [
                    'ok'          => 'bg-emerald-950/60 text-emerald-300 border-emerald-700/50',
                    'attention'   => 'bg-amber-950/60 text-amber-300 border-amber-700/50',
                    'critical'    => 'bg-red-950/60 text-red-300 border-red-700/50',
                    'unavailable' => 'bg-slate-800/60 text-slate-400 border-slate-600/50',
                ];
                $statusLabels = [
                    'ok' => 'OK', 'attention' => 'ATTENTION', 'critical' => 'CRITICAL', 'unavailable' => 'UNAVAILABLE',
                ];
            @endphp
            <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4 flex flex-col">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <h3 class="text-sm font-semibold text-slate-100">{{ strtoupper($section['label'] ?? $section['key']) }}</h3>
                    <span class="text-xs font-bold px-1.5 py-0.5 rounded border {{ $statusStyles[$section['status']] ?? $statusStyles['unavailable'] }} shrink-0">
                        {{ $statusLabels[$section['status']] ?? 'UNAVAILABLE' }}
                    </span>
                </div>
                <div class="text-xs text-slate-300 font-medium mb-2">{{ $section['title'] }}</div>
                <ul class="text-xs text-slate-400 space-y-1.5 flex-1">
                    @foreach($section['lines'] as $line)
                        <li class="leading-relaxed">• {{ $line }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>

    @if(count($digest['omitted']) > 0)
    <div class="mb-6 rounded-lg border border-slate-800 bg-slate-900/40 px-4 py-3 text-xs text-slate-400">
        <span class="font-semibold text-slate-300">Sections omitted from the message:</span>
        @foreach($digest['omitted'] as $omitted)
            <span class="ml-2">{{ strtoupper($omitted['key']) }} — {{ $omitted['reason'] }}.</span>
        @endforeach
        Omitted is not broken: a section the operator never configured stays out of the Slack text on purpose.
    </div>
    @endif

    {{-- ── The exact Slack message ───────────────────────────────────── --}}
    <section class="mb-6">
        <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">The exact Slack message</h2>
        <div class="rounded-lg border border-slate-800 bg-slate-950 px-4 py-4">
            <div class="text-xs text-slate-500 font-mono mb-2">
                🔵 *OpsCenter morning digest — {{ now()->format('Y-m-d H:i') }}* [{{ app()->environment() }}/INFO]
            </div>
            <pre class="text-xs text-slate-300 whitespace-pre-wrap font-mono leading-relaxed">{{ $text }}</pre>
        </div>
        <p class="text-xs text-slate-600 mt-2">
            Rendered by the same <span class="font-mono">compose() + render()</span> pair the 08:15 task uses —
            byte-for-byte what Slack receives (the envelope line above is added by the alert service).
        </p>
    </section>
@endif

{{-- ══ WEEKLY REVIEW (Iteration 8) ═════════════════════════════════
     The Monday deep-dive preview — same preview-is-the-message rule,
     same section-card pattern, beneath the daily digest. Read for all
     tiers; the manual send button renders for super-admins only. --}}
<section class="mt-10 pt-8 border-t border-slate-800">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
        <div>
            <h1 class="text-xl font-semibold">Weekly Review</h1>
            <p class="text-xs text-slate-400 mt-1">
                The Monday deep-dive (Mondays 08:30, right behind the daily digest): trailing-7-day trends the daily cadence cannot show.
                Informational — not a dead-man's switch; the daily digest + the watchdog carry the silence contract.
            </p>
        </div>
        <div class="flex flex-col items-end gap-2">
            @if(auth()->user()?->is_super_admin)
                <form method="POST" action="{{ route('ops.digest.weekly.send') }}">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700/80 hover:bg-emerald-600 text-xs font-medium text-slate-50 transition">
                        Send weekly review now
                    </button>
                </form>
                <span class="text-xs text-slate-500">Bypasses the dedup — for testing.</span>
            @endif
        </div>
    </div>

    {{-- Weekly meta strip --}}
    <div class="grid sm:grid-cols-3 gap-3 mb-6">
        <div class="rounded-lg border border-slate-800 bg-slate-900/40 px-4 py-3">
            <div class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-1">Next scheduled</div>
            <div class="text-sm text-slate-200">Mondays at 08:30</div>
            <div class="text-xs text-slate-500 mt-0.5">inside the morning-briefing block, before the 08:45 watchdog</div>
        </div>
        <div class="rounded-lg border border-slate-800 bg-slate-900/40 px-4 py-3">
            <div class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-1">Last sent</div>
            @if($weeklyLastSent)
                <div class="text-sm text-slate-200">{{ $weeklyLastSent['at']->diffForHumans() }}</div>
                <div class="text-xs text-slate-500 mt-0.5">trigger: {{ $weeklyLastSent['trigger'] }} · {{ $weeklyLastSent['at']->format('Y-m-d H:i') }}</div>
            @else
                <div class="text-sm text-slate-400">Not sent yet</div>
                <div class="text-xs text-slate-500 mt-0.5">the first delivery lands at the next Monday 08:30 run</div>
            @endif
        </div>
        <div class="rounded-lg border {{ $weeklyEnabled ? 'border-slate-800 bg-slate-900/40' : 'border-amber-800/50 bg-amber-950/30' }} px-4 py-3">
            <div class="text-xs uppercase tracking-wider text-slate-500 font-bold mb-1">Scheduled send</div>
            <div class="text-sm {{ $weeklyEnabled ? 'text-slate-200' : 'text-amber-300' }}">{{ $weeklyEnabled ? 'Enabled' : 'Disabled' }}</div>
            <div class="text-xs text-slate-500 mt-0.5">
                {{ $weeklyEnabled ? 'OPS_WEEKLY_REVIEW_ENABLED (default)' : 'OPS_WEEKLY_REVIEW_ENABLED=false — informational only, nothing is suspended' }}
            </div>
        </div>
    </div>

    {{-- Iteration 9: the long memory — the 8-week snapshot strip. Each
         actual delivery (scheduled or manual) persists its metrics; the
         strip turns a stack of Mondays into an arc. The partial itself
         states the cold-start honesty (no snapshots = the accumulating
         note, never a fabricated flat line). --}}
    <div class="mb-6">
        @include('ops.partials.weekly-trend-strip', ['snapshots' => $weeklySnapshots ?? []])
    </div>

    @if($weekly === null)
        <div class="rounded-lg border border-red-800/50 bg-red-950/30 px-4 py-6 text-center text-red-300 text-sm">
            The weekly review could not be composed — a data source failed before the per-section guards could catch it.
        </div>
    @else
        {{-- Weekly section cards --}}
        <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4 mb-8">
            @foreach($weekly['sections'] as $section)
                @php
                    $statusStyles = [
                        'ok'          => 'bg-emerald-950/60 text-emerald-300 border-emerald-700/50',
                        'attention'   => 'bg-amber-950/60 text-amber-300 border-amber-700/50',
                        'critical'    => 'bg-red-950/60 text-red-300 border-red-700/50',
                        'unavailable' => 'bg-slate-800/60 text-slate-400 border-slate-600/50',
                    ];
                    $statusLabels = [
                        'ok' => 'OK', 'attention' => 'ATTENTION', 'critical' => 'CRITICAL', 'unavailable' => 'UNAVAILABLE',
                    ];
                @endphp
                <div class="rounded-lg border border-slate-800 bg-slate-900/40 p-4 flex flex-col">
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <h3 class="text-sm font-semibold text-slate-100">{{ strtoupper($section['label'] ?? $section['key']) }}</h3>
                        <span class="text-xs font-bold px-1.5 py-0.5 rounded border {{ $statusStyles[$section['status']] ?? $statusStyles['unavailable'] }} shrink-0">
                            {{ $statusLabels[$section['status']] ?? 'UNAVAILABLE' }}
                        </span>
                    </div>
                    <div class="text-xs text-slate-300 font-medium mb-2">{{ $section['title'] }}</div>
                    <ul class="text-xs text-slate-400 space-y-1.5 flex-1">
                        @foreach($section['lines'] as $line)
                            <li class="leading-relaxed">• {{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        {{-- The exact weekly Slack message --}}
        <section class="mb-6">
            <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">The exact weekly Slack message</h2>
            <div class="rounded-lg border border-slate-800 bg-slate-950 px-4 py-4">
                <div class="text-xs text-slate-500 font-mono mb-2">
                    🔵 *OpsCenter weekly review — {{ now()->format('Y-m-d') }}* [{{ app()->environment() }}/INFO]
                </div>
                <pre class="text-xs text-slate-300 whitespace-pre-wrap font-mono leading-relaxed">{{ $weeklyText }}</pre>
            </div>
            <p class="text-xs text-slate-600 mt-2">
                Rendered by the same <span class="font-mono">compose() + render()</span> pair the Monday 08:30 task uses —
                byte-for-byte what Slack receives. Every section derives from the control plane's own tables (ops_events,
                ops_incidents, ops_diagnostic_runs, admin_audit_logs) — no new Sentry endpoint is speculated.
            </p>
        </section>
    @endif
</section>

<div class="mt-4 text-xs text-slate-600 space-y-1">
    <p>The silence contract: alerts fire on problems, the digest fires on TIME — an all-quiet morning still gets its message, so a silent morning is itself a signal.</p>
    <p>The digest watchdog (daily 08:45, Iteration 8) enforces that contract mechanically: a missing or stale “last sent” stamp while the digest is enabled raises one warning alert + one INFRASTRUCTURE event that auto-resolves the next healthy morning (<span class="font-mono">OPS_DIGEST_WATCHDOG_ENABLED</span>).</p>
    <p>Manual sends are super-admin only, throttled and audited (<span class="font-mono">ops.digest.sent</span> / <span class="font-mono">ops.weekly_review.sent</span>). They deliberately bypass the daily dedup: a test send that silently disappeared would look exactly like a broken webhook.</p>
    <p>The digest and the weekly review record no ops_events rows — they report on events, they must not become one.</p>
</div>
@endsection
