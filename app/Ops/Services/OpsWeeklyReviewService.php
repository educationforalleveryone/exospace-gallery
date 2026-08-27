<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Models\AdminAuditLog;
use App\Ops\Models\OpsDiagnosticRun;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use App\Ops\Models\OpsReviewSnapshot;
use App\Services\OperationalAlertService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * OpsCenter — OpsWeeklyReviewService (Iteration 8; the long memory in 9).
 *
 * The Monday deep-dive: the daily digest answers "what is broken RIGHT
 * NOW"; the weekly review answers "what KIND of week was it" — the
 * trailing-7-day trends the daily cadence cannot show: error volume by
 * category, incident throughput (opened / resolved / MTTA / MTTR),
 * deployment activity and failures, the autonomous sweep's finding
 * history, and the week's operator activity.
 *
 * ITERATION 9 — THE LONG MEMORY: every flow metric is now computed for
 * BOTH the current 7-day window AND the one before it, so each review
 * states its own week-over-week delta ("▲ +23 vs last week"). And every
 * actual delivery persists its metrics as an ops_review_snapshots row —
 * the strip on /ops/digest turns a stack of Mondays into an 8-week arc.
 * Two mechanisms, two purposes, deliberately NOT interchangeable:
 *
 *   - DELTAS are computed LIVE from window pairs, so they stay accurate
 *     even when a Monday send was missed or the platform is brand new.
 *   - SNAPSHOTS are written by every send() invocation (the /ops/digest
 *     preview composes on every page load and stays side-effect-free).
 *     The Slack dedup lives INSIDE alert(), invisible to send(), so a
 *     suppressed scheduler re-fire still records — every row's metrics
 *     are true for its week, and the strip dedupes by latest row per
 *     week_start, so duplicates are harmless by design.
 *
 * Same design contract as the morning digest (Iteration 7), inherited
 * verbatim:
 *
 *   - FAIL-SOFT PER SECTION — a throwing data source degrades exactly
 *     its own section to 'unavailable'; the review still ships.
 *   - READ-ONLY — composing reads existing tables; the only writes are
 *     the Slack message, the last-sent stamp and (Iteration 9) one
 *     snapshot row per delivery. The review records NO event rows: it
 *     REPORTS on events, it must not become one.
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
 *
 * DELTA RENDERING RULES (Iteration 9):
 *   - A line whose number is the line's primary metric gets an inline
 *     suffix: " — ▲ +N vs last week" / " — ▼ −N vs last week" /
 *     " — ±0 vs last week". Lines whose number lives in the SECTION
 *     TITLE (sweep findings, incidents opened/resolved) get one
 *     dedicated "vs last week: …" line instead — the title stays clean.
 *   - BOTH WINDOWS EMPTY → no suffix at all: an all-zero comparison is
 *     silence, not information.
 *   - MTTA/MTTR deltas render only when the previous window has its own
 *     comparable mean; "no comparison yet" states are NOT invented for
 *     missing acks — the existing acknowledge prompt already says it.
 *   - STATE metrics (active incidents, still-open sweep findings,
 *     backups) never get deltas: they are readings of NOW, not flows.
 *   - Per-check sweep lines get no per-title delta: title-keyed
 *     matching across weeks is fragile and the total delta carries the
 *     signal.
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
     *     window: array{start: CarbonInterface, previous_start: CarbonInterface},
     *     sections: array<int, array{key: string, label: string, title: string, status: string, lines: string[], metrics: array<string, mixed>}>,
     *     omitted: array<int, array{key: string, reason: string}>,
     *     metrics: array<string, array<string, mixed>>,
     * }
     */
    public function compose(): array
    {
        $sections = [];
        $omitted = [];
        $metrics = [];

        // The window pair — computed ONCE so every section compares the
        // same two windows (contiguous: [previous_start, start) then
        // [start, now]). Rolling windows anchored on now(), matching the
        // pre-Iteration-9 queries exactly.
        $currentStart = now()->subDays(7);
        $previousStart = now()->subDays(14);

        $builders = [
            'errors' => fn () => $this->errorsSection($currentStart, $previousStart),
            'incidents' => fn () => $this->incidentsSection($currentStart, $previousStart),
            'deployments' => fn () => $this->deploymentsSection($currentStart, $previousStart),
            'sweep' => fn () => $this->sweepSection($currentStart, $previousStart),
            'backups' => fn () => $this->backupsSection(),
            'activity' => fn () => $this->activitySection($currentStart, $previousStart),
        ];

        foreach ($builders as $key => $builder) {
            try {
                $section = $builder();
                if ($section !== null) {
                    $section['key'] = $key;
                    $section['label'] = self::SECTION_LABELS[$key] ?? ucfirst($key);
                    $sections[] = $section;
                    if (isset($section['metrics']) && is_array($section['metrics'])) {
                        $metrics[$key] = $section['metrics'];
                    }
                }
            } catch (Throwable $e) {
                $sections[] = [
                    'key' => $key,
                    'label' => self::SECTION_LABELS[$key] ?? ucfirst($key),
                    'title' => ucfirst($key),
                    'status' => 'unavailable',
                    'lines' => ['This section could not be composed: '.mb_substr($e->getMessage(), 0, 160)],
                    'metrics' => [],
                ];
            }
        }

        return [
            'generated_at' => now(),
            'window' => ['start' => $currentStart, 'previous_start' => $previousStart],
            'sections' => $sections,
            'omitted' => $omitted,
            'metrics' => $metrics,
        ];
    }

    /**
     * Render the exact Slack message for a compose() result. Pure and
     * deterministic — the /ops/digest preview and the real send share it.
     * Delta suffixes ride inside the section lines, so Slack and preview
     * can never drift apart on the comparison.
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
     * digest's manual send). Both record the last-sent stamp and one
     * snapshot row (Iteration 9): the snapshot describes the WEEK, so it
     * is written on every delivery attempt that got past the caller —
     * a failed webhook must not also erase the week's memory.
     *
     * @return array{sent: bool, text: string, sections: int, snapshot: bool, review: array<string, mixed>}
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

        $snapshotted = $this->persistSnapshot($review, $trigger);

        return [
            'sent' => $sent,
            'text' => $text,
            'sections' => count($review['sections']),
            'snapshot' => $snapshotted,
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

    /**
     * The 8-week arc for the /ops/digest trend strip (Iteration 9):
     * the LATEST snapshot per week_start, oldest → newest, at most
     * $limit weeks. A week with both a scheduled send and a manual
     * re-send appears once (the manual row is the later truth). Never
     * throws — a missing/unreadable table renders an empty strip, not
     * a broken page.
     *
     * @return array<int, OpsReviewSnapshot>
     */
    public function recentSnapshots(int $limit = 8): array
    {
        try {
            $rows = OpsReviewSnapshot::query()->orderByDesc('id')->get();
        } catch (Throwable) {
            return [];
        }

        // Descending id order: the FIRST row seen for a week_start is
        // that week's most recent delivery — later (lower-id) duplicates
        // for the same week are skipped, not overwritten.
        $latest = [];
        foreach ($rows as $row) {
            $key = $row->week_start->toDateString();
            if (! isset($latest[$key])) {
                $latest[$key] = $row;
            }
        }

        // Id order approximates week order but re-sends interleave —
        // sort by the week itself, then keep the most recent N weeks.
        $weeks = array_values($latest);
        usort($weeks, fn ($a, $b) => $a->week_start->timestamp <=> $b->week_start->timestamp);

        // slice(-N) returns everything when fewer than N exist.
        return array_slice($weeks, -max(1, $limit));
    }

    /**
     * Persist one delivery's metrics. Aggregate counts only — see the
     * OpsReviewSnapshot docblock. A failure here must never fail the
     * send: the strip loses one week, the operator still gets Monday's
     * message.
     *
     * @param  array<string, mixed>  $review
     */
    private function persistSnapshot(array $review, string $trigger): bool
    {
        try {
            OpsReviewSnapshot::create([
                'week_start' => ($review['window']['start'] ?? now()->subDays(7))->toDateString(),
                'week_end' => now()->toDateString(),
                'trigger' => $trigger,
                'metrics' => is_array($review['metrics'] ?? null) ? $review['metrics'] : [],
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    // ── Delta helpers ─────────────────────────────────────────────────────

    /**
     * The compact week-over-week delta for an integer flow metric:
     * '▲ +N' / '▼ −N' / '±0' / '' — for dedicated "vs last week:" lines.
     * Empty string when BOTH windows are zero (all-zero comparisons are
     * silence, not information). The arrow states DIRECTION only — the
     * reader knows whether "more" is bad for the metric in question.
     *
     * No multibyte trim() anywhere near this: the arrows are 3-byte UTF-8
     * and byte-wise trimming would corrupt them.
     */
    private function deltaText(int $current, int $previous): string
    {
        if ($current === 0 && $previous === 0) {
            return '';
        }

        if ($current > $previous) {
            return sprintf('▲ +%d', $current - $previous);
        }
        if ($current < $previous) {
            return sprintf('▼ −%d', $previous - $current);
        }

        return '±0';
    }

    /**
     * The inline suffix form (" — ▲ +N vs last week") for lines whose
     * primary metric is the line's own number.
     */
    private function deltaSuffix(int $current, int $previous): string
    {
        $text = $this->deltaText($current, $previous);

        return $text === '' ? '' : ' — '.$text.' vs last week';
    }

    /**
     * The inline suffix for an hour-valued mean (MTTA/MTTR). Empty when
     * the previous window has no comparable mean — no "no comparison"
     * prose is invented (see the class docblock's delta rules).
     */
    private function deltaSuffixHours(?float $currentHours, ?float $previousHours): string
    {
        if ($currentHours === null || $previousHours === null) {
            return '';
        }

        $diff = $currentHours - $previousHours;
        if (abs($diff) < 0.05) {
            return ' — ±0.0 h vs last week';
        }

        return $diff > 0
            ? sprintf(' — ▲ +%.1f h vs last week', $diff)
            : sprintf(' — ▼ −%.1f h vs last week', abs($diff));
    }

    // ── Section builders ──────────────────────────────────────────────────

    /**
     * Error volume by category: events FIRST SEEN in the trailing 7 d,
     * grouped by category — the "what kind of week was it" histogram.
     * Top 5 categories by occurrence-weighted count + the total.
     *
     * @return array{title: string, status: string, lines: string[], metrics: array<string, mixed>}
     */
    private function errorsSection(CarbonInterface $currentStart, CarbonInterface $previousStart): array
    {
        $counts = OpsEvent::query()
            ->where('first_seen_at', '>=', $currentStart)
            ->selectRaw('category, COUNT(*) as n, COALESCE(SUM(occurrence_count), 0) as occurrences')
            ->groupBy('category')
            ->orderByDesc('n')
            ->get();

        // The previous window, keyed by category for per-line deltas.
        $previousCounts = OpsEvent::query()
            ->where('first_seen_at', '>=', $previousStart)
            ->where('first_seen_at', '<', $currentStart)
            ->selectRaw('category, COUNT(*) as n')
            ->groupBy('category')
            ->pluck('n', 'category');

        $previousTotal = (int) $previousCounts->sum();

        if ($counts->isEmpty()) {
            return [
                'title' => 'no new error events',
                'status' => 'ok',
                'lines' => [$previousTotal > 0
                    ? 'Nothing entered the event store in the last 7 days'.$this->deltaSuffix(0, $previousTotal).'.'
                    : 'Nothing entered the event store in the last 7 days.'],
                'metrics' => ['total' => 0, 'categories' => [], 'occurrences' => 0],
            ];
        }

        $total = (int) $counts->sum('n');
        $lines = [];
        $categories = [];
        foreach ($counts->take(5) as $row) {
            $occurrences = (int) $row->occurrences;
            $lines[] = sprintf(
                '%s: %d event(s)%s%s',
                $row->category,
                (int) $row->n,
                $occurrences > (int) $row->n ? sprintf(' (%d occurrences)', $occurrences) : '',
                $this->deltaSuffix((int) $row->n, (int) ($previousCounts[$row->category] ?? 0)),
            );
            $categories[(string) $row->category] = (int) $row->n;
        }
        if ($counts->count() > 5) {
            $lines[] = sprintf('%d more categories…', $counts->count() - 5);
        }

        return [
            'title' => $total.' new event(s) across '.$counts->count().' categories',
            'status' => 'ok', // informational: volume is a fact, not a verdict
            'lines' => $lines,
            'metrics' => [
                'total' => $total,
                'categories' => $categories,
                'occurrences' => (int) $counts->sum('occurrences'),
            ],
        ];
    }

    /**
     * Incident throughput: opened / still-active / resolved in the
     * window, plus MTTA + MTTR computed over the incidents RESOLVED in
     * the last 7 d (the only population with a complete timeline).
     *
     * @return array{title: string, status: string, lines: string[], metrics: array<string, mixed>}
     */
    private function incidentsSection(CarbonInterface $currentStart, CarbonInterface $previousStart): array
    {
        $opened = (int) OpsIncident::query()
            ->where('first_event_at', '>=', $currentStart)
            ->count();
        $active = (int) OpsIncident::query()
            ->whereIn('status', ['open', 'acknowledged'])
            ->count();
        $resolvedRows = OpsIncident::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $currentStart)
            ->whereNotNull('first_event_at')
            ->get();

        // The previous window's flows for the "vs last week" line.
        $previousOpened = (int) OpsIncident::query()
            ->where('first_event_at', '>=', $previousStart)
            ->where('first_event_at', '<', $currentStart)
            ->count();
        $previousResolvedRows = OpsIncident::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $previousStart)
            ->where('resolved_at', '<', $currentStart)
            ->whereNotNull('first_event_at')
            ->get();

        $mttaMinutes = null;
        $mttrMinutes = null;

        if ($opened === 0 && $active === 0 && $resolvedRows->isEmpty() && $previousOpened === 0 && $previousResolvedRows->isEmpty()) {
            return [
                'title' => 'no incidents',
                'status' => 'ok',
                'lines' => ['Nothing correlated into an incident this week.'],
                'metrics' => ['opened' => 0, 'resolved' => 0, 'active' => 0, 'mtta_minutes' => null, 'mttr_minutes' => null],
            ];
        }

        $lines = [
            sprintf('%d opened, %d resolved, %d still active.', $opened, $resolvedRows->count(), $active),
        ];

        // The dedicated delta line — opened/resolved live in the title
        // and the first line already packs three numbers. deltaText()
        // returns '' only when BOTH windows are zero, which the guard
        // already excluded per pair — '±0' covers a zero/zero PAIR inside
        // a line whose other half has a story.
        if (! ($opened === 0 && $previousOpened === 0 && $resolvedRows->isEmpty() && $previousResolvedRows->isEmpty())) {
            $lines[] = sprintf(
                'vs last week: opened %s, resolved %s',
                $this->deltaText($opened, $previousOpened) ?: '±0',
                $this->deltaText($resolvedRows->count(), $previousResolvedRows->count()) ?: '±0',
            );
        }

        $previousMttrMinutes = $this->previousMeanMinutes($previousResolvedRows);
        $previousMttaMinutes = $this->previousAckMeanMinutes($previousResolvedRows);

        if ($resolvedRows->isNotEmpty()) {
            // MTTR: first event → resolved, over the resolved population.
            // (Carbon 3's diffInSeconds is SIGNED: start.diff(end) is
            // positive — resolved.diff(first) would go backwards.)
            $durations = $resolvedRows
                ->filter(fn ($i) => $i->first_event_at !== null && $i->resolved_at !== null)
                ->map(fn ($i) => $i->first_event_at->diffInSeconds($i->resolved_at));
            if ($durations->isNotEmpty()) {
                $mttrMinutes = $durations->average() / 60;
                $lines[] = sprintf(
                    'MTTR %.1f h (mean, %d resolved)%s.',
                    $mttrMinutes / 60,
                    $durations->count(),
                    $this->deltaSuffixHours($mttrMinutes / 60, $previousMttrMinutes !== null ? $previousMttrMinutes / 60 : null),
                );
            }

            // MTTA: first event → acknowledged, only where acknowledged.
            $acks = $resolvedRows
                ->filter(fn ($i) => $i->first_event_at !== null && $i->acknowledged_at !== null)
                ->map(fn ($i) => $i->first_event_at->diffInSeconds($i->acknowledged_at));
            if ($acks->isNotEmpty()) {
                $mttaMinutes = $acks->average() / 60;
                $lines[] = sprintf(
                    'MTTA %.1f h (mean, %d acknowledged)%s.',
                    $mttaMinutes / 60,
                    $acks->count(),
                    $this->deltaSuffixHours($mttaMinutes / 60, $previousMttaMinutes !== null ? $previousMttaMinutes / 60 : null),
                );
            } else {
                $lines[] = 'MTTA: no acknowledged timestamps this week (acknowledge incidents to track response time).';
            }
        }

        return [
            'title' => $opened.' opened, '.$resolvedRows->count().' resolved',
            'status' => 'ok', // informational recap; live severity rides the daily digest
            'lines' => $lines,
            'metrics' => [
                'opened' => $opened,
                'resolved' => $resolvedRows->count(),
                'active' => $active,
                'mtta_minutes' => $mttaMinutes !== null ? round($mttaMinutes, 1) : null,
                'mttr_minutes' => $mttrMinutes !== null ? round($mttrMinutes, 1) : null,
            ],
        ];
    }

    /**
     * Deployment activity: BUILD/DEPLOYMENT-category events first seen
     * in the window, with the failure slice broken out.
     *
     * @return array{title: string, status: string, lines: string[], metrics: array<string, mixed>}
     */
    private function deploymentsSection(CarbonInterface $currentStart, CarbonInterface $previousStart): array
    {
        $deployments = OpsEvent::query()
            ->where('first_seen_at', '>=', $currentStart)
            ->whereIn('category', ['BUILD', 'DEPLOYMENT']);

        $total = (int) (clone $deployments)->count();
        $failed = (int) (clone $deployments)
            ->whereIn('severity', ['error', 'critical'])
            ->count();

        $previousDeployments = OpsEvent::query()
            ->where('first_seen_at', '>=', $previousStart)
            ->where('first_seen_at', '<', $currentStart)
            ->whereIn('category', ['BUILD', 'DEPLOYMENT']);
        $previousTotal = (int) (clone $previousDeployments)->count();
        $previousFailed = (int) (clone $previousDeployments)
            ->whereIn('severity', ['error', 'critical'])
            ->count();

        if ($total === 0 && $previousTotal === 0) {
            return ['title' => 'no deployments', 'status' => 'ok', 'lines' => ['No build or deployment events synced in the last 7 days.'], 'metrics' => ['total' => 0, 'failed' => 0]];
        }

        if ($total === 0) {
            return ['title' => 'no deployments', 'status' => 'ok', 'lines' => ['No build or deployment events synced in the last 7 days'.$this->deltaSuffix(0, $previousTotal).'.'], 'metrics' => ['total' => 0, 'failed' => 0]];
        }

        $lines = [sprintf('%d deployment event(s) recorded%s', $total, $this->deltaSuffix($total, $previousTotal))];
        if ($failed > 0) {
            $lines[] = sprintf('%d with error severity — see the Events page filtered to DEPLOYMENT%s', $failed, $this->deltaSuffix($failed, $previousFailed));
        }

        return [
            'title' => $total.' deployment(s)'.($failed > 0 ? ', '.$failed.' failed' : ''),
            'status' => $failed > 0 ? 'attention' : 'ok',
            'lines' => $lines,
            'metrics' => ['total' => $total, 'failed' => $failed],
        ];
    }

    /**
     * The autonomous sweep's week: how many findings fired, which checks
     * produced them, and how many are still open. A busy-but-clean week
     * (many findings, all resolved) is the sweep doing its job — the
     * still-open count is the part that needs a human.
     *
     * @return array{title: string, status: string, lines: string[], metrics: array<string, mixed>}
     */
    private function sweepSection(CarbonInterface $currentStart, CarbonInterface $previousStart): array
    {
        $findings = OpsEvent::query()
            ->where('source', 'sweep')
            ->where('first_seen_at', '>=', $currentStart)
            ->get();

        $previousFindings = (int) OpsEvent::query()
            ->where('source', 'sweep')
            ->where('first_seen_at', '>=', $previousStart)
            ->where('first_seen_at', '<', $currentStart)
            ->count();

        if ($findings->isEmpty() && $previousFindings === 0) {
            return ['title' => 'no sweep findings', 'status' => 'ok', 'lines' => ['The autonomous sweep found nothing worth recording all week.'], 'metrics' => ['findings' => 0, 'open' => 0]];
        }

        if ($findings->isEmpty()) {
            return ['title' => 'no sweep findings', 'status' => 'ok', 'lines' => ['The autonomous sweep found nothing worth recording all week'.$this->deltaSuffix(0, $previousFindings).'.'], 'metrics' => ['findings' => 0, 'open' => 0]];
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
        // The findings total lives in the title → one dedicated delta line.
        $lines[] = 'vs last week: '.($this->deltaText($findings->count(), $previousFindings) ?: '±0');
        $lines[] = $stillOpen > 0
            ? $stillOpen.' finding(s) still open — the daily digest tracks them.'
            : 'All findings resolved.';

        return [
            'title' => $findings->count().' finding(s), '.$stillOpen.' open',
            'status' => $stillOpen > 0 ? 'attention' : 'ok',
            'lines' => $lines,
            'metrics' => ['findings' => (int) $findings->count(), 'open' => $stillOpen],
        ];
    }

    /**
     * Backups — CURRENT state, framed honestly: the weekly review does
     * not fabricate a 7-day history the control plane does not store; it
     * restates the same freshness facts the daily digest and the score's
     * data-protection component use (one fact layer — three surfaces).
     * No delta by design: freshness is a reading of NOW, not a flow.
     *
     * @return array{title: string, status: string, lines: string[], metrics: array<string, mixed>}
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

        return ['title' => 'current freshness (not a 7-day history)', 'status' => $status, 'lines' => $lines, 'metrics' => ['status' => (string) ($backup['status'] ?? 'unknown')]];
    }

    /**
     * The week's operator activity: diagnostic runs + audited ops.*
     * actions + the most active actor. Informational by design.
     *
     * @return array{title: string, status: string, lines: string[], metrics: array<string, mixed>}
     */
    private function activitySection(CarbonInterface $currentStart, CarbonInterface $previousStart): array
    {
        $runs = OpsDiagnosticRun::query()
            ->with('actor')
            ->where('created_at', '>=', $currentStart)
            ->get();

        $actions = (int) AdminAuditLog::query()
            ->where('action', 'like', 'ops.%')
            ->where('created_at', '>=', $currentStart)
            ->count();

        $previousRuns = (int) OpsDiagnosticRun::query()
            ->where('created_at', '>=', $previousStart)
            ->where('created_at', '<', $currentStart)
            ->count();
        $previousActions = (int) AdminAuditLog::query()
            ->where('action', 'like', 'ops.%')
            ->where('created_at', '>=', $previousStart)
            ->where('created_at', '<', $currentStart)
            ->count();

        if ($runs->isEmpty() && $actions === 0 && $previousRuns === 0 && $previousActions === 0) {
            return ['title' => 'no operator activity', 'status' => 'ok', 'lines' => ['No diagnostic runs and no audited ops actions this week.'], 'metrics' => ['runs' => 0, 'actions' => 0]];
        }

        $lines = [];
        if (! $runs->isEmpty() || $previousRuns > 0) {
            $byActor = $runs->groupBy(fn ($run) => $run->actor?->name ?? $run->actor?->email ?? 'unknown actor');
            $top = $byActor->sortByDesc(fn ($group) => $group->count())->keys()->first();
            $base = $runs->isEmpty()
                ? 'no diagnostic runs'
                : sprintf('%d diagnostic run(s), most by %s', $runs->count(), $top ?? 'unknown actor');
            $lines[] = $base.$this->deltaSuffix($runs->count(), $previousRuns);
        }
        if ($actions > 0 || $previousActions > 0) {
            $lines[] = $actions.' audited ops action(s)'.$this->deltaSuffix($actions, $previousActions);
        }

        return ['title' => $runs->count().' run(s), '.$actions.' action(s)', 'status' => 'ok', 'lines' => $lines, 'metrics' => ['runs' => (int) $runs->count(), 'actions' => $actions]];
    }

    /**
     * The previous window's MTTR mean in MINUTES (null when the previous
     * window resolved nothing — no comparable baseline).
     */
    private function previousMeanMinutes($previousResolvedRows): ?float
    {
        $durations = $previousResolvedRows
            ->filter(fn ($i) => $i->first_event_at !== null && $i->resolved_at !== null)
            ->map(fn ($i) => $i->first_event_at->diffInSeconds($i->resolved_at));

        return $durations->isNotEmpty() ? $durations->average() / 60 : null;
    }

    /**
     * The previous window's MTTA mean in MINUTES (null when nothing
     * resolved last week was ever acknowledged).
     */
    private function previousAckMeanMinutes($previousResolvedRows): ?float
    {
        $acks = $previousResolvedRows
            ->filter(fn ($i) => $i->first_event_at !== null && $i->acknowledged_at !== null)
            ->map(fn ($i) => $i->first_event_at->diffInSeconds($i->acknowledged_at));

        return $acks->isNotEmpty() ? $acks->average() / 60 : null;
    }
}
