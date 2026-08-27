<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Models\AdminAuditLog;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsDiagnosticRun;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use App\Services\OperationalAlertService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * OpsCenter — OpsMorningDigestService (Iteration 7).
 *
 * The unified morning briefing: ONE Slack message a day that answers
 * "what happened, what is still broken, what did we do about it" across
 * every domain the control plane watches — health score, incidents,
 * untriaged errors, applications, the autonomous sweep, backups, the
 * billing-webhook ledger, the Sentry 24 h trend, credential rotation
 * and the last 24 h of operator activity.
 *
 * Design contract (the OpsCenter rules, applied to a report):
 *
 *   - FAIL-SOFT PER SECTION. Every section builder is individually
 *     guarded; a data source that throws degrades that section to
 *     status 'unavailable' with an honest line — the rest of the
 *     briefing still goes out. A broken digest must never be a
 *     missing digest.
 *   - READ-ONLY. Composing reports reads existing tables and caches;
 *     the only writes are the Slack alert itself and the last-sent
 *     stamp. No events are recorded for the digest — the digest
 *     REPORTS on events, it must not become one (365 rows/year of
 *     "digest sent" would be pure noise in the inventory).
 *   - SILENCE IS THE ANOMALY. Alerts fire on problems; the digest
 *     fires on TIME. While enabled it sends every day — including the
 *     boring "all quiet" days — so a silent morning becomes a signal
 *     in itself (the dead-man's-switch rule, §16.4 of the manual).
 *     The kill switch OPS_MORNING_DIGEST_ENABLED=false turns it off
 *     entirely; it gates the SCHEDULED send only — a manual "send
 *     now" from /ops/digest is an explicit, audited human action.
 *   - THE PREVIEW IS THE MESSAGE. /ops/digest renders the exact text
 *     Slack receives, from the same compose()+render() pair — the
 *     preview can never drift from the real thing. Sections whose
 *     data source is not configured (e.g. Sentry without a token)
 *     are OMITTED from the message and listed under "omitted" on the
 *     preview — tight messages beat apologetic ones.
 */
class OpsMorningDigestService
{
    /** Cache stamp: when the digest last went out, and from where. */
    private const STAMP_KEY = 'ops:morning-digest:last';

    /** The scheduled send's Slack dedup key (info TTL: 6 h). */
    private const DEDUP_KEY = 'ops.morning.digest';

    /**
     * The human-facing section names (Slack + preview share them, so
     * the message and the page can never disagree about terminology).
     */
    public const SECTION_LABELS = [
        'health' => 'Platform',
        'incidents' => 'Incidents',
        'errors' => 'Untriaged errors',
        'applications' => 'Applications',
        'sweep' => 'Autonomous sweep',
        'backups' => 'Backups',
        'webhooks' => 'Webhooks',
        'sentry' => 'Sentry (24 h)',
        'credentials' => 'Credentials',
        'activity' => 'Operator activity',
    ];

    public function __construct(
        private readonly OpsHealthScoreService $score,
        private readonly OpsStatusTilesService $tiles,
        private readonly OpsSweepStatusService $sweepStatus,
        private readonly OpsCredentialInventoryService $credentials,
    ) {}

    /**
     * Assemble the briefing. Never throws — every section is guarded;
     * a total failure (the outer catch) still yields a deliverable
     * digest with a single honest 'unavailable' line.
     *
     * @return array{
     *     generated_at: CarbonInterface,
     *     sections: array<int, array{key: string, title: string, status: string, lines: string[]}],
     *     omitted: array<int, array{key: string, reason: string}>,
     * }
     */
    public function compose(): array
    {
        $sections = [];
        $omitted = [];

        $builders = [
            'health' => fn () => $this->healthSection(),
            'incidents' => fn () => $this->incidentsSection(),
            'errors' => fn () => $this->errorsSection(),
            'applications' => fn () => $this->applicationsSection(),
            'sweep' => fn () => $this->sweepSection(),
            'backups' => fn () => $this->backupsSection(),
            'webhooks' => fn () => $this->webhooksSection(),
            // NOT an arrow fn: $omitted must be captured BY REFERENCE so
            // the omission note survives the call (arrow functions copy).
            'sentry' => function () use (&$omitted): ?array {
                return $this->sentrySection($omitted);
            },
            'credentials' => fn () => $this->credentialsSection(),
            'activity' => fn () => $this->activitySection(),
        ];

        foreach ($builders as $key => $builder) {
            try {
                $section = $builder();
                if ($section !== null) {
                    $section['key'] = $key;
                    $section['label'] = self::SECTION_LABELS[$key] ?? ucfirst($key);
                    $sections[] = $section;
                }
            } catch (Throwable $e) {
                $sections[] = [
                    'key' => $key,
                    'label' => self::SECTION_LABELS[$key] ?? ucfirst($key),
                    'title' => ucfirst($key),
                    'status' => 'unavailable',
                    'lines' => ['This section could not be composed: '.mb_substr($e->getMessage(), 0, 160)],
                ];
            }
        }

        return [
            'generated_at' => now(),
            'sections' => $sections,
            'omitted' => $omitted,
        ];
    }

    /**
     * Render the exact Slack message for a compose() result. Pure and
     * deterministic — the /ops/digest preview and the real send both
     * call this, so they can never disagree.
     *
     * @param  array<string, mixed>  $digest
     */
    public function render(array $digest): string
    {
        $blocks = [];
        foreach ($digest['sections'] ?? [] as $section) {
            $block = strtoupper((string) ($section['label'] ?? $section['key'] ?? '')).': '.(string) ($section['title'] ?? '');
            foreach ((array) ($section['lines'] ?? []) as $line) {
                $block .= "\n• ".(string) $line;
            }
            $blocks[] = $block;
        }

        $url = rtrim((string) config('app.url'), '/');
        $footer = $url !== '' ? "Full detail: {$url}/ops" : 'Full detail: /ops';

        return implode("\n\n", $blocks)."\n\n".$footer;
    }

    /**
     * Compose, render and deliver. $trigger 'scheduled' (the daily
     * command — dedup-suppressed within the service's 6 h info TTL) or
     * 'manual' (the /ops/digest button — never dedup-suppressed: a test
     * send that silently disappears would look exactly like a broken
     * webhook). Both record the last-sent stamp.
     *
     * @return array{sent: bool, text: string, sections: int, digest: array<string, mixed>}
     */
    public function send(string $trigger = 'scheduled'): array
    {
        $digest = $this->compose();
        $text = $this->render($digest);
        $sent = false;

        try {
            app(OperationalAlertService::class)->alert(
                'OpsCenter morning digest — '.now()->format('Y-m-d H:i'),
                $text,
                'info',
                $trigger === 'scheduled' ? self::DEDUP_KEY : null,
            );
            $sent = true;
        } catch (Throwable) {
            // The alert service already swallows webhook transport
            // failures; this only fires on catastrophic setup. Never
            // fatal — the stamp is still written, the failure is
            // visible on /ops/digest (last sent ≠ scheduled clean).
        }

        try {
            Cache::put(self::STAMP_KEY, ['at' => now(), 'trigger' => $trigger], now()->addDays(7));
        } catch (Throwable) {
            // Cache unavailable — worst case: the page loses "last sent".
        }

        return [
            'sent' => $sent,
            'text' => $text,
            'sections' => count($digest['sections']),
            'digest' => $digest,
        ];
    }

    /**
     * When did the digest last go out? (shown on /ops/digest).
     *
     * @return array{at: CarbonInterface, trigger: string}|null
     */
    public function lastSent(): ?array
    {
        try {
            $stamp = Cache::get(self::STAMP_KEY);
            $at = is_array($stamp) ? ($stamp['at'] ?? null) : null;
            if ($at instanceof CarbonInterface) {
                return ['at' => $at, 'trigger' => (string) ($stamp['trigger'] ?? 'unknown')];
            }
        } catch (Throwable) {
            // Cache unavailable — honest null.
        }

        return null;
    }

    // ── Section builders (each returns the section or null to omit) ──

    /**
     * @return array{title: string, status: string, lines: string[]}
     */
    private function healthSection(): array
    {
        $health = $this->score->computeLive();
        $score = (int) $health['score'];
        $band = (string) $health['band'];

        $lines = [];
        foreach ((array) ($health['applied_caps'] ?? []) as $cap) {
            $lines[] = 'Verdict cap applied — '.$cap;
        }

        // The weakest components, worst first: the reasons ARE the story.
        $components = collect((array) ($health['components'] ?? []))
            ->sortBy('score')
            ->take(3);
        foreach ($components as $component) {
            if ((int) $component['score'] >= 100) {
                continue;
            }
            $reason = (string) ($component['reasons'][0] ?? 'score '.$component['score'].'/100');
            $lines[] = $component['name'].': '.$reason;
        }

        if ($lines === []) {
            $lines[] = 'Every component is at 100 — nothing to flag.';
        }

        return [
            'title' => sprintf('%d/100 (%s)', $score, strtoupper($band)),
            'status' => $band === 'healthy' ? 'ok' : ($band === 'degraded' ? 'attention' : 'critical'),
            'lines' => $lines,
        ];
    }

    /**
     * @return array{title: string, status: string, lines: string[]}
     */
    private function incidentsSection(): array
    {
        $counts = OpsIncident::query()
            ->whereIn('status', ['open', 'acknowledged'])
            ->selectRaw('severity, COUNT(*) as n')
            ->groupBy('severity')
            ->pluck('n', 'severity');

        $total = (int) $counts->sum();
        if ($total === 0) {
            return ['title' => 'no active incidents', 'status' => 'ok', 'lines' => ['Nothing correlated into an active incident.']];
        }

        $parts = [];
        foreach (['critical', 'error', 'warning'] as $severity) {
            if ((int) ($counts[$severity] ?? 0) > 0) {
                $parts[] = (int) $counts[$severity].' '.$severity;
            }
        }

        $lines = [];
        $worst = OpsIncident::query()
            ->with('application')
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByRaw('CASE severity WHEN "critical" THEN 1 WHEN "error" THEN 2 WHEN "warning" THEN 3 ELSE 4 END')
            ->orderByDesc('last_event_at')
            ->limit(3)
            ->get();
        foreach ($worst as $incident) {
            $age = $incident->first_event_at !== null
                ? $incident->first_event_at->diffForHumans()
                : 'unknown age';
            $lines[] = sprintf(
                '#%d "%s" (%s, %s, since %s)',
                $incident->id,
                mb_substr((string) $incident->title, 0, 80),
                $incident->application?->name ?? 'unknown app',
                $incident->severity,
                $age,
            );
        }

        $status = (int) ($counts['critical'] ?? 0) > 0 ? 'critical' : 'attention';

        return [
            'title' => $total.' active ('.implode(', ', $parts).')',
            'status' => $status,
            'lines' => $lines,
        ];
    }

    /**
     * Untriaged errors: open/acknowledged events NOT inside an active
     * incident — the same double-count rule the health score applies
     * (an error that belongs to an incident is counted as part of that
     * incident, never twice).
     *
     * @return array{title: string, status: string, lines: string[]}
     */
    private function errorsSection(): array
    {
        $activeIncidentIds = OpsIncident::query()
            ->whereIn('status', ['open', 'acknowledged'])
            ->pluck('id');

        $query = OpsEvent::query()
            ->whereIn('status', ['open', 'acknowledged'])
            ->whereIn('severity', ['critical', 'error', 'warning']);

        if ($activeIncidentIds->isNotEmpty()) {
            $query->where(fn ($q) => $q
                ->whereNull('ops_incident_id')
                ->orWhereNotIn('ops_incident_id', $activeIncidentIds->all()));
        }

        $counts = (clone $query)->selectRaw('severity, COUNT(*) as n')
            ->groupBy('severity')
            ->pluck('n', 'severity');

        $total = (int) $counts->sum();
        if ($total === 0) {
            return ['title' => 'no untriaged errors', 'status' => 'ok', 'lines' => ['Every open error is either triaged into an incident or resolved.']];
        }

        $parts = [];
        foreach (['critical', 'error', 'warning'] as $severity) {
            if ((int) ($counts[$severity] ?? 0) > 0) {
                $parts[] = (int) $counts[$severity].' '.$severity;
            }
        }

        $lines = [];
        $worst = (clone $query)->with('application')
            ->orderByRaw('CASE severity WHEN "critical" THEN 1 WHEN "error" THEN 2 WHEN "warning" THEN 3 ELSE 4 END')
            ->orderByDesc('last_seen_at')
            ->limit(3)
            ->get();
        foreach ($worst as $event) {
            $lines[] = sprintf(
                '%s — %s',
                $event->application?->name ?? 'unknown app',
                mb_substr((string) $event->title, 0, 90),
            );
        }

        $status = (int) ($counts['critical'] ?? 0) > 0 ? 'critical' : 'attention';

        return [
            'title' => $total.' untriaged ('.implode(', ', $parts).')',
            'status' => $status,
            'lines' => $lines,
        ];
    }

    /**
     * @return array{title: string, status: string, lines: string[]}
     */
    private function applicationsSection(): array
    {
        $applications = OpsApplication::query()
            ->orderByDesc('is_self')
            ->orderBy('kind')
            ->orderBy('name')
            ->get();

        if ($applications->isEmpty()) {
            return [
                'title' => 'no applications synced yet',
                'status' => 'attention',
                'lines' => ['The platform sync has not populated ops_applications — check /ops/applications after the next sync.'],
            ];
        }

        $byHealth = ['running' => 0, 'degraded' => 0, 'stopped' => 0, 'unknown' => 0];
        foreach ($applications as $application) {
            $health = (string) $application->health;
            $byHealth[$health] = ($byHealth[$health] ?? 0) + 1;
        }

        $title = sprintf(
            '%d apps — %d running, %d degraded, %d stopped',
            $applications->count(),
            $byHealth['running'],
            $byHealth['degraded'],
            $byHealth['stopped'],
        );

        // Worst offenders by the SAME sub-score the Applications page
        // shows (§16.2) — the digest can never disagree with the UI.
        $lines = [];
        try {
            $scores = $this->score->computeForApplications($applications);
            $offenders = [];
            foreach ($applications as $application) {
                $entry = $scores[$application->id] ?? null;
                if ($entry !== null && $entry['band'] !== 'healthy') {
                    $offenders[] = ['name' => $application->name, 'score' => (int) $entry['score'], 'band' => $entry['band']];
                }
            }
            usort($offenders, fn ($a, $b) => $a['score'] <=> $b['score']);
            foreach (array_slice($offenders, 0, 3) as $offender) {
                $lines[] = sprintf('%s — %d/100 (%s)', $offender['name'], $offender['score'], $offender['band']);
            }
        } catch (Throwable) {
            // Sub-scores unavailable — the health rollup above still ships.
        }

        if ($lines === []) {
            $lines[] = 'Every application is in the healthy band.';
        }

        $status = $byHealth['stopped'] > 0 ? 'critical' : (($byHealth['degraded'] > 0 || count($lines) > 1 || ($lines[0] ?? '') !== 'Every application is in the healthy band.') ? 'attention' : 'ok');

        return ['title' => $title, 'status' => $status, 'lines' => $lines];
    }

    /**
     * The autonomous sweep's state, via the same reader the Diagnostics
     * cadence panel uses.
     *
     * @return array{title: string, status: string, lines: string[]}
     */
    private function sweepSection(): array
    {
        $status = $this->sweepStatus->status();

        if (! $status['enabled']) {
            return [
                'title' => 'autonomous sweep disabled',
                'status' => 'attention',
                'lines' => ['OPS_SWEEP_ENABLED=false — the platform is unwatched between operator visits.'],
            ];
        }

        $open = array_values(array_filter($status['checks'], fn ($check) => $check['has_open_event']));

        if ($open !== []) {
            return [
                'title' => count($open).' open sweep finding(s)',
                'status' => 'critical',
                'lines' => array_map(
                    fn ($check) => $check['label'].' — see its event for findings and next steps',
                    array_slice($open, 0, 4),
                ),
            ];
        }

        return [
            'title' => 'all '.count($status['checks']).' swept checks healthy',
            'status' => 'ok',
            'lines' => ['No open findings from the autonomous sweep (last '.count($status['checks']).' checks probed within their cadences).'],
        ];
    }

    /**
     * @return array{title: string, status: string, lines: string[]}
     */
    private function backupsSection(): array
    {
        $backup = $this->tiles->backupStatus();

        $lines = [];
        foreach ((array) ($backup['disks'] ?? []) as $disk) {
            $lines[] = sprintf(
                '%s: %s%s',
                $disk['disk'],
                $disk['status'],
                $disk['newest_age_hours'] !== null ? sprintf(' (newest %.1f h old)', (float) $disk['newest_age_hours']) : '',
            );
        }
        if ($lines === []) {
            $lines[] = (string) ($backup['reasons'][0] ?? 'No backup disks configured.');
        }

        $status = match ((string) ($backup['status'] ?? 'unknown')) {
            'healthy' => 'ok',
            'degraded', 'unknown' => 'attention',
            default => 'critical',
        };

        return ['title' => 'backup freshness', 'status' => $status, 'lines' => $lines];
    }

    /**
     * @return array{title: string, status: string, lines: string[]}
     */
    private function webhooksSection(): array
    {
        $webhooks = $this->tiles->webhookStatus();
        $failed = (int) ($webhooks['failed_count'] ?? 0);

        if ($failed === 0) {
            $processed = (int) ($webhooks['processed_24h'] ?? 0);
            $line = $processed > 0
                ? sprintf('%d billing webhook(s) processed in the last 24 h, none failed.', $processed)
                : 'No failed billing webhooks.';

            return ['title' => 'webhook ledger clean', 'status' => 'ok', 'lines' => [$line]];
        }

        $oldest = $webhooks['oldest_failed_age_hours'] !== null
            ? sprintf(', oldest %.1f h old', (float) $webhooks['oldest_failed_age_hours'])
            : '';

        return [
            'title' => $failed.' failed webhook(s)',
            'status' => $failed > 5 ? 'critical' : 'attention',
            'lines' => [sprintf('Billing events are not being processed%s — replay from the Actions hub.', $oldest)],
        ];
    }

    /**
     * Omitted (not degraded) when the Sentry API is unconfigured — the
     * operator never asked for this section. Configured but failing
     * DOES surface: silence about a configured data source would look
     * exactly like "no errors".
     *
     * @param  array<int, array{key: string, reason: string}>  $omitted
     * @return array{title: string, status: string, lines: string[]}|null
     */
    private function sentrySection(array &$omitted): ?array
    {
        $trend = app(SentryApiClient::class)->trend();

        if (! ($trend['configured'] ?? false)) {
            $omitted[] = ['key' => 'sentry', 'reason' => 'Sentry API token not configured (SENTRY_API_TOKEN)'];

            return null;
        }

        if (isset($trend['error'])) {
            return [
                'title' => 'Sentry trend unavailable',
                'status' => 'attention',
                'lines' => [(string) $trend['error']],
            ];
        }

        $total = (int) ($trend['total'] ?? 0);
        if ($total === 0) {
            return ['title' => 'no Sentry events in 24 h', 'status' => 'ok', 'lines' => ['The org-wide error trend is flat at zero.']];
        }

        return [
            'title' => sprintf('%d Sentry events in 24 h', $total),
            'status' => 'ok',
            'lines' => [sprintf('Peak %d/h around %s.', (int) ($trend['peak'] ?? 0), (string) ($trend['peak_hour'] ?? 'unknown hour'))],
        ];
    }

    /**
     * @return array{title: string, status: string, lines: string[]}
     */
    private function credentialsSection(): array
    {
        $inventory = $this->credentials->inventory();
        $counts = (array) ($inventory['counts'] ?? []);

        $actionable = (int) ($counts['rotate_now'] ?? 0) + (int) ($counts['overdue'] ?? 0);
        $dueSoon = (int) ($counts['due_soon'] ?? 0);

        if ($actionable > 0) {
            return [
                'title' => $actionable.' credential surface(s) need rotation',
                'status' => 'attention',
                'lines' => [sprintf(
                    'The 09:00 reminder alert carries the full list; work it at /ops/credentials%s.',
                    $dueSoon > 0 ? " ({$dueSoon} more due soon)" : '',
                )],
            ];
        }

        if ($dueSoon > 0) {
            return [
                'title' => 'in cadence, '.$dueSoon.' due soon',
                'status' => 'ok',
                'lines' => ['Nothing overdue — plan the upcoming rotations on /ops/credentials.'],
            ];
        }

        return ['title' => 'all surfaces in cadence', 'status' => 'ok', 'lines' => ['Every tracked credential is within its rotation cadence.']];
    }

    /**
     * The operator-tier audit digest (the Iteration-6 handoff item):
     * what the humans DID in the last 24 h — diagnostic runs by actor
     * plus every audited ops.* action. Informational, never a problem
     * signal: a quiet day is a valid state, not a warning.
     *
     * @return array{title: string, status: string, lines: string[]}
     */
    private function activitySection(): array
    {
        $runs = OpsDiagnosticRun::query()
            ->with('actor')
            ->where('created_at', '>=', now()->subDay())
            ->get();

        $actions = AdminAuditLog::query()
            ->where('action', 'like', 'ops.%')
            ->where('created_at', '>=', now()->subDay())
            ->get();

        if ($runs->isEmpty() && $actions->isEmpty()) {
            return ['title' => 'no operator activity in 24 h', 'status' => 'ok', 'lines' => ['No diagnostic runs and no audited ops actions in the last 24 hours.']];
        }

        $lines = [];

        if (! $runs->isEmpty()) {
            $byActor = $runs->groupBy(fn ($run) => $run->actor?->name ?? $run->actor?->email ?? 'unknown actor');
            $byActor->sortByDesc(fn ($group) => $group->count())->take(3)
                ->each(function ($group, $actor) use (&$lines): void {
                    $lines[] = sprintf('%d diagnostic run(s) by %s', $group->count(), $actor);
                });
        }

        if (! $actions->isEmpty()) {
            $names = $actions->pluck('action')->unique()->sort()->values()->take(5)->implode(', ');
            $lines[] = $actions->count().' audited ops action(s): '.$names;
        }

        return ['title' => 'operator activity (last 24 h)', 'status' => 'ok', 'lines' => $lines];
    }
}
