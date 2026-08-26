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
