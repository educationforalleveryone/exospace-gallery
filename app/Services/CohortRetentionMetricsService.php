<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RetentionSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CohortRetentionMetricsService
{
    public function matrix(int $weeks = 8): array
    {
        $weeks = max(2, min(25, $weeks));

        return Cache::flexible(
            "retention:matrix:{$weeks}",
            [now()->addMinutes(30), now()->addMinutes(60)],
            fn () => $this->compute($weeks),
        );
    }

    public function compute(int $weeks = 8): array
    {
        $weeks = max(2, min(25, $weeks));
        $now = CarbonImmutable::now();
        $thisWeekStart = $now->startOfWeek();

        $cohorts = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $weekStart = $thisWeekStart->subWeeks($i);
            $weekEnd = $weekStart->addWeek();

            $size = User::where('created_at', '>=', $weekStart)
                ->where('created_at', '<', $weekEnd)
                ->count();

            $cells = [];
            for ($w = 0; $w <= $i; $w++) {
                $periodStart = $weekStart->addWeeks($w);
                $periodEnd = $periodStart->addWeek();

                $active = $this->countActive($weekStart, $weekEnd, $periodStart, $periodEnd);

                $cells[$w] = [
                    'pct'      => $size > 0 ? round(($active / $size) * 100, 1) : 0.0,
                    'active'   => $active,
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

    public function trend(int $weekIndex = 1, int $limit = 26): array
    {
        $weekIndex = max(0, min(10, $weekIndex));

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

    public function cohortDrilldown(string $weekStart, int $weekIndex): ?array
    {
        // Parse + validate the cohort week start. Must be a real date.
        try {
            $start = CarbonImmutable::parse($weekStart)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if (! $start->isMonday()) {
            return null;
        }

        $now = CarbonImmutable::now();
        if ($start > $now || $start < $now->copy()->subWeeks(156)) {
            return null;
        }

        if ($weekIndex < 0 || $weekIndex > 156) {
            return null;
        }
        $periodStart = $start->addWeeks($weekIndex);
        $periodEnd = $periodStart->addWeek();
        if ($periodStart > $now) {
            return null;
        }

        $cohortEnd = $start->addWeek();

        $size = User::where('created_at', '>=', $start)
            ->where('created_at', '<', $cohortEnd)
            ->count();

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

    public function cohortCurve(string $cohortWeekStart, int $maxWeeks = 8): array
    {
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

        $rows = DB::table('retention_snapshots')
            ->select('week_index', 'cohort_size', 'active_count', 'retained_pct', 'captured_at')
            ->where('cohort_week_start', $start->toDateString())
            ->where('week_index', '<', $maxWeeks)
            ->get()
            ->groupBy('week_index')
            ->map(fn ($group) => $group->sortByDesc('captured_at')->first());

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
                        ->where('galleries.updated_at', '>=', $periodStart)
                        ->where('galleries.updated_at', '<', $periodEnd);
                });
            })
            ->count();
    }

    private function quoteDateColumn(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "CAST({$column} AS TEXT)";
        }

        return $column;
    }
}
