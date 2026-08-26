<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RetentionSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ITERATION 6 — cohort retention metrics, shared source of truth.
 *
 * History: the retention matrix lived only inside the weekly
 * exospace:cohort-retention console command (console output + a log
 * line), the same blindness TTFE had before Iteration 5. Worse, the
 * command's activity definition was WRONG in two ways:
 *
 *   1. "users.updated_at >= period start" is UNBOUNDED — activity after
 *      a period counted as activity IN that period (every later-active
 *      user inflated every earlier week).
 *   2. users.updated_at is bumped by plan changes, marketing prefs, 2FA
 *      setup, admin writes — not product engagement.
 *
 * This service defines activity truthfully and BOUNDED — a user is
 * active during week [start, end) iff:
 *   - users.last_login_at falls in the window (stamped by the
 *     StampLastLogin listener since Iteration 6; NULL = not logged in
 *     since the column shipped), OR
 *   - any of their galleries has updated_at in the window (deliberately
 *     including soft-deleted galleries — the user genuinely worked on
 *     their exhibition that week; deleting it later must not erase the
 *     engagement fact).
 *
 * Cohort membership is users.created_at (all users; banned users remain
 * in their cohort — churn SHOULD read as non-retention).
 *
 * Scale notes: one small indexed COUNT per (cohort × week) cell — a
 * weeks=8 run executes at most 36 bounded queries. The dashboard reads
 * through Cache::flexible (30/60 min); the weekly command reads fresh
 * (a persisted history row must reflect the moment it was captured).
 */
class CohortRetentionMetricsService
{
    /**
     * Live retention matrix for the dashboard (cached 30/60 min).
     *
     * @return array{
     *     weeks: int,
     *     cohorts: array<int, array{
     *         week_start: string, label: string, size: int,
     *         cells: array<int, array{pct: float, active: int, complete: bool}>
     *     }>
     * }
     */
    public function matrix(int $weeks = 8): array
    {
        $weeks = max(2, min(25, $weeks));

        return Cache::flexible(
            "retention:matrix:{$weeks}",
            [now()->addMinutes(30), now()->addMinutes(60)],
            fn () => $this->compute($weeks),
        );
    }

    /**
     * Uncached computation — the weekly command and tests call this
     * directly so persisted history and reports reflect the moment of
     * capture, not a possibly-stale dashboard cache entry.
     *
     * @return array{weeks: int, cohorts: array<int, array{week_start: string, label: string, size: int, cells: array<int, array{pct: float, active: int, complete: bool}>}>}
     */
    public function compute(int $weeks = 8): array
    {
        $weeks = max(2, min(25, $weeks));
        $now = CarbonImmutable::now();
        $thisWeekStart = $now->startOfWeek();

        $cohorts = [];

        // Oldest cohort first — matches the matrix table layout and the
        // W0..W7 left-to-right reading direction.
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $weekStart = $thisWeekStart->subWeeks($i);
            $weekEnd = $weekStart->addWeek();

            $size = User::where('created_at', '>=', $weekStart)
                ->where('created_at', '<', $weekEnd)
                ->count();

            $cells = [];
            // ITERATION-6 FIX (inherited from the original command): the
            // triangle now points the right way. A cohort i weeks back has
            // cells W0..Wi — its W_i IS the current (partial) week. The old
            // `weeks - i` bound gave mid-age cohorts too FEW cells (their
            // recent weeks vanished from an 8-week matrix) and the newest
            // cohort FUTURE weeks rendered as misleading zeros.
            for ($w = 0; $w <= $i; $w++) {
                $periodStart = $weekStart->addWeeks($w);
                $periodEnd = $periodStart->addWeek();

                $active = $this->countActive($weekStart, $weekEnd, $periodStart, $periodEnd);

                $cells[$w] = [
                    'pct'      => $size > 0 ? round(($active / $size) * 100, 1) : 0.0,
                    'active'   => $active,
                    // A cell is final once its week has closed; the live
                    // matrix still shows partial values (dimmed in the UI)
                    // but ONLY complete cells are persisted.
                    'complete' => $now >= $periodEnd,
                ];
            }

            $cohorts[] = [
                'week_start' => $weekStart->toDateString(),
                'label'      => $weekStart->format('M j'),
                'size'       => $size,
                'cells'      => $cells,
            ];
        }

        return ['weeks' => $weeks, 'cohorts' => $cohorts];
    }

    /**
     * ITERATION 6 — persist the current matrix's COMPLETE cells as
     * point-in-time history (idempotent within the capture hour).
     *
     * @return int number of cells persisted
     */
    public function persist(int $weeks = 8): int
    {
        $weeks = max(2, min(25, $weeks));
        $matrix = $this->compute($weeks);
        $capturedAt = now()->startOfHour();

        $persisted = 0;

        foreach ($matrix['cohorts'] as $cohort) {
            foreach ($cohort['cells'] as $weekIndex => $cell) {
                if (! $cell['complete'] || $cohort['size'] === 0) {
                    continue;
                }

                RetentionSnapshot::updateOrCreate(
                    [
                        'cohort_week_start' => $cohort['week_start'],
                        'week_index'        => $weekIndex,
                        'captured_at'       => $capturedAt,
                    ],
                    [
                        'cohort_size'  => $cohort['size'],
                        'active_count' => $cell['active'],
                        'retained_pct' => $cell['pct'],
                    ],
                );
                $persisted++;
            }
        }

        return $persisted;
    }

    /**
     * ITERATION 6 — retention trend for the Master Control chart.
     *
     * One point per weekly capture: the retention of the LATEST cohort
     * whose week-index week had closed by that capture (at a Monday 06:00
     * capture, W1's latest complete cohort is the one registered two
     * weeks prior). Rows are chronological (oldest first) so the chart
     * feeds labels/data directly.
     *
     * Tiny table (≈ weeks × 52 rows/year, 2-year retention) — cheap
     * indexed read, deliberately uncached like the onboarding trend.
     *
     * @return array<int, array{captured_at: string, captured_on: string, cohort: string, retained_pct: ?float}>
     */
    public function trend(int $weekIndex = 1, int $limit = 26): array
    {
        $weekIndex = max(0, min(10, $weekIndex));

        // Step 1: latest complete cohort per capture for this week index
        // (max(cohort_week_start) grouped by captured_at).
        $captures = DB::table('retention_snapshots')
            ->select('captured_at', DB::raw('MAX(' . $this->quoteDateColumn('cohort_week_start') . ') as latest_cohort'))
            ->where('week_index', $weekIndex)
            ->groupBy('captured_at')
            ->orderByDesc('captured_at')
            ->limit(max(1, min(156, $limit)))
            ->get();

        if ($captures->isEmpty()) {
            return [];
        }

        // Step 2: fetch those exact rows.
        $rows = DB::table('retention_snapshots')
            ->where('week_index', $weekIndex)
            ->whereIn('captured_at', $captures->pluck('captured_at')->all())
            ->get()
            ->keyBy(fn ($r) => $r->captured_at . '|' . $r->cohort_week_start);

        $out = [];
        foreach ($captures as $capture) {
            $row = $rows->get($capture->captured_at . '|' . $capture->latest_cohort);
            if ($row === null) {
                continue;
            }

            $out[] = [
                'captured_at'   => \Carbon\Carbon::parse($capture->captured_at)->format('M j'),
                'captured_on'   => \Carbon\Carbon::parse($capture->captured_at)->toDateString(),
                'cohort'        => \Carbon\Carbon::parse($capture->latest_cohort)->format('M j'),
                'retained_pct'  => $row->retained_pct !== null ? (float) $row->retained_pct : null,
            ];
        }

        // Chronological, oldest first (matches the onboarding trend shape).
        return array_reverse($out);
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * ITERATION 7 — cohort drill-down: the users BEHIND a matrix cell.
     *
     * The dashboard matrix shows aggregates (size + active count + pct);
     * an operator investigating churn needs the underlying user list
     * ("which 3 of the 12 from Aug 25's cohort came back in week 2?").
     * This method returns the cohort's members as a paginatable query,
     * each row tagged with an `active_in_period` flag computed from
     * the SAME bounded activity definition as countActive() so the
     * drill-down's active count always reconciles with the matrix cell
     * the operator clicked.
     *
     * Returns null when the inputs don't describe a real, in-range,
     * already-started (cohort × period) cell — the controller turns
     * that into a 404. A size-0 cohort returns a valid result with an
     * empty query (no members to list).
     *
     * @return ?array{
     *     week_start: CarbonImmutable,
     *     week_index: int,
     *     period: array{start: CarbonImmutable, end: CarbonImmutable},
     *     size: int,
     *     active_count: int,
     *     members: \Illuminate\Database\Eloquent\Builder
     * }
     */
    public function cohortDrilldown(string $weekStart, int $weekIndex): ?array
    {
        // Parse + validate the cohort week start. Must be a real date.
        try {
            $start = CarbonImmutable::parse($weekStart)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        // Cohort keys in the matrix are always Mondays — refuse anything
        // else so a hand-typed URL can't fabricate a non-cohort "cohort".
        if (! $start->isMonday()) {
            return null;
        }

        // Bound the lookup so an attacker can't scan arbitrary history.
        // The matrix renders the last 8 weeks; snapshots keep 2 years.
        // 3 years (156 weeks) is a generous ceiling that lets an operator
        // review recent churn without unbounded scanning.
        $now = CarbonImmutable::now();
        if ($start > $now || $start < $now->copy()->subWeeks(156)) {
            return null;
        }

        // Week index sane + period has already started (future weeks have
        // no data; cells beyond the cohort's age can't exist on the matrix).
        if ($weekIndex < 0 || $weekIndex > 156) {
            return null;
        }
        $periodStart = $start->addWeeks($weekIndex);
        $periodEnd = $periodStart->addWeek();
        if ($periodStart > $now) {
            return null;
        }

        $cohortEnd = $start->addWeek();

        // Cohort membership count — same query as compute() so size
        // reconciles. (Members query runs separately so a tiny cohort
        // doesn't pay a full-table scan on the count.)
        $size = User::where('created_at', '>=', $start)
            ->where('created_at', '<', $cohortEnd)
            ->count();

        // Members query, each row tagged with the activity flag from
        // the SAME bounded definition as countActive() (last_login_at
        // in [periodStart, periodEnd) OR any gallery — including
        // soft-deleted — updated in the window). selectRaw with bound
        // parameters is portable across SQLite + MySQL; Laravel's
        // paginate() strips columns for the count query so the alias
        // doesn't break pagination.
        $members = User::where('created_at', '>=', $start)
            ->where('created_at', '<', $cohortEnd)
            ->orderBy('created_at')
            ->selectRaw(
                'users.*, CASE WHEN (users.last_login_at >= ? AND users.last_login_at < ?) '
                . 'OR EXISTS (SELECT 1 FROM galleries WHERE galleries.user_id = users.id '
                . 'AND galleries.updated_at >= ? AND galleries.updated_at < ?) '
                . 'THEN 1 ELSE 0 END AS active_in_period',
                [
                    $periodStart, $periodEnd,
                    $periodStart, $periodEnd,
                ],
            );

        // Active count from the SAME definition as countActive() —
        // re-derived live (not read from the matrix cache) so the
        // drill-down reflects the moment of the click, not a possibly
        // stale 30/60-min dashboard cache entry.
        $activeCount = $this->countActive($start, $cohortEnd, $periodStart, $periodEnd);

        return [
            'week_start'    => $start,
            'week_index'     => $weekIndex,
            'period'         => ['start' => $periodStart, 'end' => $periodEnd],
            'size'           => $size,
            'active_count'   => $activeCount,
            'members'         => $members,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * ITERATION 9 — per-cohort retention curve W0..W7 for the drill-down page.
     *
     * The Master Control matrix trends only W1 + W2 (the headline retention
     * metric); an operator investigating churn in a specific cohort needs
     * "which week did the drop happen?" — W3..W7 retention for THAT cohort.
     * This returns the latest complete retention value per week_index for
     * the given cohort, drawn from the same retention_snapshots table the
     * weekly command persists.
     *
     * Output: a list of {week_index, retained_pct, cohort_size, active_count,
     * complete} — `complete` mirrors the matrix convention (a week is final
     * once it has closed). Partial weeks (the cohort's still-running follow-
     * up weeks) are returned so the chart can render them dimmed — same
     * convention as the matrix cells.
     *
     * Returns an empty list when:
     *   - the cohort has zero members (no curve to chart), OR
     *   - no snapshots have been persisted for this cohort yet (the weekly
     *     command hasn't run since the cohort's W0 closed). The drill-down
     *     page renders the no-data state in that case rather than a half-
     *     populated chart.
     *
     * @return list<array{week_index: int, retained_pct: ?float, cohort_size: int, active_count: int, complete: bool}>
     */
    public function cohortCurve(string $cohortWeekStart, int $maxWeeks = 8): array
    {
        // Parse + validate the cohort week start (same rules as
        // cohortDrilldown — must be a real Monday, within the 3-year
        // bound). An invalid input is the caller's bug, not a chartable
        // state; return [] so the page renders the empty state cleanly.
        try {
            $start = CarbonImmutable::parse($cohortWeekStart)->startOfDay();
        } catch (\Throwable) {
            return [];
        }
        if (! $start->isMonday()) {
            return [];
        }
        $now = CarbonImmutable::now();
        if ($start > $now || $start < $now->copy()->subWeeks(156)) {
            return [];
        }

        $maxWeeks = max(1, min(25, $maxWeeks));

        // One row per week_index (0..maxWeeks-1) — the LATEST snapshot for
        // each (cohort × week) pair. The retention_snapshots unique index is
        // (cohort_week_start, week_index, captured_at); grouped + max-
        // captured_at gives the most recent complete capture per cell. The
        // matrix's persist() only writes complete cells, so every persisted
        // row is final for the live cohort at capture time.
        $rows = DB::table('retention_snapshots')
            ->select('week_index', 'cohort_size', 'active_count', 'retained_pct', 'captured_at')
            ->where('cohort_week_start', $start->toDateString())
            ->where('week_index', '<', $maxWeeks)
            ->get()
            ->groupBy('week_index')
            ->map(fn ($group) => $group->sortByDesc('captured_at')->first());

        // ITERATION 9 contract: return [] when NO snapshots have been
        // persisted for this cohort yet (the weekly command hasn't run
        // since the cohort's W0 closed). The drill-down page renders the
        // no-data state cleanly when curve is empty; the alternative —
        // returning 8 rows with all-null retained_pct — would also work
        // (the blade filters them out) but the empty-array contract is
        // simpler to assert against in tests + tells the caller "no
        // data" rather than "data is null" (a meaningful distinction
        // for callers who want to render a different surface when the
        // curve is empty vs partial).
        if ($rows->isEmpty()) {
            return [];
        }

        $out = [];
        for ($w = 0; $w < $maxWeeks; $w++) {
            $periodStart = $start->copy()->addWeeks($w);
            $periodEnd = $periodStart->copy()->addWeek();
            $complete = $now >= $periodEnd;
            $row = $rows->get($w);
            $out[] = [
                'week_index'    => $w,
                'retained_pct'  => $row?->retained_pct !== null ? (float) $row->retained_pct : null,
                'cohort_size'   => $row?->cohort_size ?? 0,
                'active_count'  => $row?->active_count ?? 0,
                'complete'      => $complete,
            ];
        }

        return $out;
    }

    /**
     * Bounded activity count for one (cohort, period) pair.
     *
     * Active = last_login_at in [periodStart, periodEnd) OR any gallery
     * (including soft-deleted) updated in the window.
     */
    private function countActive(
        CarbonInterface $cohortStart,
        CarbonInterface $cohortEnd,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): int {
        return User::where('created_at', '>=', $cohortStart)
            ->where('created_at', '<', $cohortEnd)
            ->where(function ($q) use ($periodStart, $periodEnd) {
                $q->where(function ($sq) use ($periodStart, $periodEnd) {
                    $sq->where('last_login_at', '>=', $periodStart)
                        ->where('last_login_at', '<', $periodEnd);
                })->orWhereExists(function ($sq) use ($periodStart, $periodEnd) {
                    $sq->select(DB::raw(1))
                        ->from('galleries')
                        ->whereColumn('galleries.user_id', 'users.id')
                        // Soft-deleted galleries intentionally included —
                        // working on a since-deleted gallery was still
                        // engagement that week.
                        ->where('galleries.updated_at', '>=', $periodStart)
                        ->where('galleries.updated_at', '<', $periodEnd);
                });
            })
            ->count();
    }

    /**
     * Quote a DATE column inside an aggregate portably: MySQL needs no
     * cast (column type is already DATE); SQLite stores dates as TEXT
     * where MAX() is lexicographic — correct for ISO strings — but the
     * cast keeps both drivers explicit and equal.
     */
    private function quoteDateColumn(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "CAST({$column} AS TEXT)";
        }

        return $column;
    }
}
