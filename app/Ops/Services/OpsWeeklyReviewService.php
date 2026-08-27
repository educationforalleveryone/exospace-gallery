<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Models\AdminAuditLog;
use App\Ops\Models\OpsDiagnosticRun;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use App\Services\OperationalAlertService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * OpsCenter — OpsWeeklyReviewService (Iteration 8).
 *
 * The Monday deep-dive: the daily digest answers "what is broken RIGHT
 * NOW"; the weekly review answers "what KIND of week was it" — the
 * trailing-7-day trends the daily cadence cannot show: error volume by
 * category, incident throughput (opened / resolved / MTTA / MTTR),
 * deployment activity and failures, the autonomous sweep's finding
 * history, and the week's operator activity.
 *
 * Same design contract as the morning digest (Iteration 7), inherited
 * verbatim:
 *
 *   - FAIL-SOFT PER SECTION — a throwing data source degrades exactly
 *     its own section to 'unavailable'; the review still ships.
 *   - READ-ONLY — composing reads existing tables; the only writes are
 *     the Slack message and the last-sent stamp. The review records NO
 *     event rows: it REPORTS on events, it must not become one.
 *   - SILENCE IS FOR PROBLEMS — the weekly review is NOT a dead-man's
 *     switch. Unlike the daily digest it is informational: kill switch
 *     OPS_WEEKLY_REVIEW_ENABLED (default on) turns it off without
 *     suspending any contract — the daily digest + the watchdog carry
 *     the silence contract, this carries the long view.
 *   - THE PREVIEW IS THE MESSAGE — /ops/digest renders the exact text
 *     from the same compose()+render() pair the Monday 08:30 task uses.
 *   - LOCAL DATA ONLY — every section derives from the control plane's
 *     own tables (ops_events, ops_incidents, ops_diagnostic_runs,
 *     admin_audit_logs). No new Sentry endpoint is speculated: the 24 h
 *     trend already rides the daily digest, and a weekly Sentry window
 *     is a separate API surface nobody has asked for yet.
 */
class OpsWeeklyReviewService
{
    /** Cache stamp: when the weekly review last went out, and from where. */
    private const STAMP_KEY = 'ops:weekly-review:last';

    /** The scheduled send's Slack dedup key (info TTL: 6 h). */
    private const DEDUP_KEY = 'ops.weekly.review';

    /**
     * The human-facing section names (Slack + preview share them).
     */
    public const SECTION_LABELS = [
        'errors' => 'Errors by category (7 d)',
        'incidents' => 'Incident throughput (7 d)',
        'deployments' => 'Deployments (7 d)',
        'sweep' => 'Sweep findings (7 d)',
        'backups' => 'Backups (current)',
        'activity' => 'Operator activity (7 d)',
    ];

    public function __construct(
        private readonly OpsStatusTilesService $tiles,
    ) {}

    /**
     * Assemble the review. Never throws — every section is guarded.
     *
     * @return array{
     *     generated_at: CarbonInterface,
     *     sections: array<int, array{key: string, title: string, status: string, lines: string[]}>,
     *     omitted: array<int, array{key: string, reason: string}>,
     * }
     */
    public function compose(): array
    {
        $sections = [];
        $omitted = [];

        $builders = [
            'errors' => fn () => $this->errorsSection(),
            'incidents' => fn () => $this->incidentsSection(),
            'deployments' => fn () => $this->deploymentsSection(),
            'sweep' => fn () => $this->sweepSection(),
            'backups' => fn () => $this->backupsSection(),
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
     * deterministic — the /ops/digest preview and the real send share it.
     *
     * @param  array<string, mixed>  $review
     */
    public function render(array $review): string
    {
        $blocks = [];
        foreach ($review['sections'] ?? [] as $section) {
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
     * Compose, render and deliver. $trigger 'scheduled' (Mondays 08:30 —
     * dedup-suppressed within the 6 h info TTL) or 'manual' (the digest
     * page button — never dedup-suppressed, same rationale as the daily
     * digest's manual send). Both record the last-sent stamp.
     *
     * @return array{sent: bool, text: string, sections: int, review: array<string, mixed>}
     */
    public function send(string $trigger = 'scheduled'): array
    {
        $review = $this->compose();
        $text = $this->render($review);
        $sent = false;

        try {
            app(OperationalAlertService::class)->alert(
                'OpsCenter weekly review — '.now()->format('Y-m-d'),
                $text,
                'info',
                $trigger === 'scheduled' ? self::DEDUP_KEY : null,
            );
            $sent = true;
        } catch (Throwable) {
            // Same contract as the digest: never fatal, the stamp is
            // still written, the failure is visible on the digest page.
        }

        try {
            Cache::put(self::STAMP_KEY, ['at' => now(), 'trigger' => $trigger], now()->addDays(14));
        } catch (Throwable) {
            // Cache unavailable — worst case: the page loses "last sent".
        }

        return [
            'sent' => $sent,
            'text' => $text,
            'sections' => count($review['sections']),
            'review' => $review,
        ];
    }

    /**
     * When did the weekly review last go out? (shown on /ops/digest).
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

    // ── Section builders ──────────────────────────────────────────────────

    /**
     * Error volume by category: events FIRST SEEN in the trailing 7 d,
     * grouped by category — the "what kind of week was it" histogram.
     * Top 5 categories by occurrence-weighted count + the total.
     *
     * @return array{title: string, status: string, lines: string[]}
     */
    private function errorsSection(): array
    {
        $counts = OpsEvent::query()
            ->where('first_seen_at', '>=', now()->subDays(7))
            ->selectRaw('category, COUNT(*) as n, COALESCE(SUM(occurrence_count), 0) as occurrences')
            ->groupBy('category')
            ->orderByDesc('n')
            ->get();

        if ($counts->isEmpty()) {
            return ['title' => 'no new error events', 'status' => 'ok', 'lines' => ['Nothing entered the event store in the last 7 days.']];
        }

        $total = (int) $counts->sum('n');
        $lines = [];
        foreach ($counts->take(5) as $row) {
            $occurrences = (int) $row->occurrences;
            $lines[] = sprintf(
                '%s: %d event(s)%s',
                $row->category,
                (int) $row->n,
                $occurrences > (int) $row->n ? sprintf(' (%d occurrences)', $occurrences) : '',
            );
        }
        if ($counts->count() > 5) {
            $lines[] = sprintf('%d more categories…', $counts->count() - 5);
        }

        return [
            'title' => $total.' new event(s) across '.$counts->count().' categories',
            'status' => 'ok', // informational: volume is a fact, not a verdict
            'lines' => $lines,
        ];
    }

    /**
     * Incident throughput: opened / still-active / resolved in the
     * window, plus MTTA + MTTR computed over the incidents RESOLVED in
     * the last 7 d (the only population with a complete timeline).
     *
     * @return array{title: string, status: string, lines: string[]}
     */
    private function incidentsSection(): array
    {
        $opened = (int) OpsIncident::query()
            ->where('first_event_at', '>=', now()->subDays(7))
            ->count();
        $active = (int) OpsIncident::query()
            ->whereIn('status', ['open', 'acknowledged'])
            ->count();
        $resolvedRows = OpsIncident::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', now()->subDays(7))
            ->whereNotNull('first_event_at')
            ->get();

        if ($opened === 0 && $active === 0 && $resolvedRows->isEmpty()) {
            return ['title' => 'no incidents', 'status' => 'ok', 'lines' => ['Nothing correlated into an incident this week.']];
        }

        $lines = [
            sprintf('%d opened, %d resolved, %d still active.', $opened, $resolvedRows->count(), $active),
        ];

        if ($resolvedRows->isNotEmpty()) {
            // MTTR: first event → resolved, over the resolved population.
            // (Carbon 3's diffInSeconds is SIGNED: start.diff(end) is
            // positive — resolved.diff(first) would go backwards.)
            $durations = $resolvedRows
                ->filter(fn ($i) => $i->first_event_at !== null && $i->resolved_at !== null)
                ->map(fn ($i) => $i->first_event_at->diffInSeconds($i->resolved_at));
            if ($durations->isNotEmpty()) {
                $lines[] = sprintf('MTTR %.1f h (mean, %d resolved).', $durations->average() / 3600, $durations->count());
            }

            // MTTA: first event → acknowledged, only where acknowledged.
            $acks = $resolvedRows
                ->filter(fn ($i) => $i->first_event_at !== null && $i->acknowledged_at !== null)
                ->map(fn ($i) => $i->first_event_at->diffInSeconds($i->acknowledged_at));
            $lines[] = $acks->isNotEmpty()
                ? sprintf('MTTA %.1f h (mean, %d acknowledged).', $acks->average() / 3600, $acks->count())
                : 'MTTA: no acknowledged timestamps this week (acknowledge incidents to track response time).';
        }

        return [
            'title' => $opened.' opened, '.$resolvedRows->count().' resolved',
            'status' => 'ok', // informational recap; live severity rides the daily digest
            'lines' => $lines,
        ];
    }

    /**
     * Deployment activity: BUILD/DEPLOYMENT-category events first seen
     * in the window, with the failure slice broken out.
     *
     * @return array{title: string, status: string, lines: string[]}
     */
    private function deploymentsSection(): array
    {
        $deployments = OpsEvent::query()
            ->where('first_seen_at', '>=', now()->subDays(7))
            ->whereIn('category', ['BUILD', 'DEPLOYMENT']);

        $total = (int) (clone $deployments)->count();
        if ($total === 0) {
            return ['title' => 'no deployments', 'status' => 'ok', 'lines' => ['No build or deployment events synced in the last 7 days.']];
        }

        $failed = (int) (clone $deployments)
            ->whereIn('severity', ['error', 'critical'])
            ->count();

        $lines = [sprintf('%d deployment event(s) recorded.', $total)];
        if ($failed > 0) {
            $lines[] = sprintf('%d with error severity — see the Events page filtered to DEPLOYMENT.', $failed);
        }

        return [
            'title' => $total.' deployment(s)'.($failed > 0 ? ', '.$failed.' failed' : ''),
            'status' => $failed > 0 ? 'attention' : 'ok',
            'lines' => $lines,
        ];
    }

    /**
     * The autonomous sweep's week: how many findings fired, which checks
     * produced them, and how many are still open. A busy-but-clean week
     * (many findings, all resolved) is the sweep doing its job — the
     * still-open count is the part that needs a human.
     *
     * @return array{title: string, status: string, lines: string[]}
     */
    private function sweepSection(): array
    {
        $findings = OpsEvent::query()
            ->where('source', 'sweep')
            ->where('first_seen_at', '>=', now()->subDays(7))
            ->get();

        if ($findings->isEmpty()) {
            return ['title' => 'no sweep findings', 'status' => 'ok', 'lines' => ['The autonomous sweep found nothing worth recording all week.']];
        }

        $stillOpen = (int) $findings->whereIn('status', ['open', 'acknowledged'])->count();

        $byCheck = $findings
            ->groupBy(fn ($e) => mb_substr((string) $e->title, 0, 60))
            ->map(fn ($group) => $group->count())
            ->sortDesc()
            ->take(3);

        $lines = [];
        foreach ($byCheck as $title => $count) {
            $lines[] = sprintf('%s — %d firing(s)', $title, $count);
        }
        $lines[] = $stillOpen > 0
            ? $stillOpen.' finding(s) still open — the daily digest tracks them.'
            : 'All findings resolved.';

        return [
            'title' => $findings->count().' finding(s), '.$stillOpen.' open',
            'status' => $stillOpen > 0 ? 'attention' : 'ok',
            'lines' => $lines,
        ];
    }

    /**
     * Backups — CURRENT state, framed honestly: the weekly review does
     * not fabricate a 7-day history the control plane does not store; it
     * restates the same freshness facts the daily digest and the score's
     * data-protection component use (one fact layer — three surfaces).
     *
     * @return array{title: string, status: string, lines: string[]}
     */
    private function backupsSection(): array
    {
        $backup = $this->tiles->backupStatus();

        $lines = [];
        foreach ((array) ($backup['disks'] ?? []) as $disk) {
            $lines[] = sprintf(
                '%s: %s (%d archive(s), newest %s)',
                $disk['disk'],
                $disk['status'],
                (int) ($disk['file_count'] ?? 0),
                $disk['newest_age_hours'] !== null ? sprintf('%.1f h old', (float) $disk['newest_age_hours']) : 'none',
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

        return ['title' => 'current freshness (not a 7-day history)', 'status' => $status, 'lines' => $lines];
    }

    /**
     * The week's operator activity: diagnostic runs + audited ops.*
     * actions + the most active actor. Informational by design.
     *
     * @return array{title: string, status: string, lines: string[]}
     */
    private function activitySection(): array
    {
        $runs = OpsDiagnosticRun::query()
            ->with('actor')
            ->where('created_at', '>=', now()->subDays(7))
            ->get();

        $actions = AdminAuditLog::query()
            ->where('action', 'like', 'ops.%')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        if ($runs->isEmpty() && $actions === 0) {
            return ['title' => 'no operator activity', 'status' => 'ok', 'lines' => ['No diagnostic runs and no audited ops actions this week.']];
        }

        $lines = [];
        if (! $runs->isEmpty()) {
            $byActor = $runs->groupBy(fn ($run) => $run->actor?->name ?? $run->actor?->email ?? 'unknown actor');
            $top = $byActor->sortByDesc(fn ($group) => $group->count())->keys()->first();
            $lines[] = sprintf('%d diagnostic run(s), most by %s.', $runs->count(), $top);
        }
        if ($actions > 0) {
            $lines[] = $actions.' audited ops action(s).';
        }

        return ['title' => $runs->count().' run(s), '.$actions.' action(s)', 'status' => 'ok', 'lines' => $lines];
    }
}
