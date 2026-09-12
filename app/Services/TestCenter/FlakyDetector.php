<?php

declare(strict_types=1);

namespace App\Services\TestCenter;

use App\Models\QaTestRun;
use Illuminate\Support\Collection;

class FlakyDetector
{
    public function __construct(
        private readonly int $recentRuns = 20,
        private readonly int $minExecutions = 4,
        private readonly float $flakyBelowPassRate = 95.0,
    ) {}

    public function detect(?string $profile = null): Collection
    {
        $runsQuery = QaTestRun::query()
            ->whereIn('status', [QaTestRun::STATUS_PASSED, QaTestRun::STATUS_FAILED])
            ->orderByDesc('id')
            ->limit($this->recentRuns * 6);

        if ($profile !== null) {
            $runsQuery->where('profile', $profile);
        }

        $runIds      = $runsQuery->pluck('id');
        $profileOfRun= QaTestRun::whereIn('id', $runIds)->pluck('profile', 'id');

        if ($runIds->isEmpty()) {
            return collect();
        }

        $rows = \DB::table('qa_test_case_results as c')
            ->join('qa_test_runs as r', 'r.id', '=', 'c.qa_test_run_id')
            ->whereIn('c.qa_test_run_id', $runIds)
            ->whereIn('c.status', ['passed', 'failed', 'error', 'timed_out'])
            ->when($profile !== null, fn ($q) => $q->where('r.profile', $profile))
            ->orderByDesc('c.qa_test_run_id')
            ->get(['c.qa_test_run_id', 'c.test_identifier', 'c.status', 'c.message', 'r.profile']);

        $grouped = [];

        foreach ($rows as $row) {
            $key   = ($row->profile ?? '?').'|'.$row->test_identifier;
            $runId = $row->qa_test_run_id;
            $isProblem = in_array($row->status, ['failed', 'error', 'timed_out'], true);

            if (! isset($grouped[$key][$runId])) {
                $grouped[$key][$runId] = [
                    'problem'     => false,
                    'message'     => null,
                    'profile'     => $row->profile,
                    'identifier'  => $row->test_identifier,
                ];
            }

            if ($isProblem && ! $grouped[$key][$runId]['problem']) {
                $grouped[$key][$runId]['problem'] = true;
                $grouped[$key][$runId]['message'] = $row->message;
            }
        }

        $results = collect();

        foreach ($grouped as $key => $perRun) {
            $executions = count($perRun);

            if ($executions < $this->minExecutions) {
                continue;
            }

            krsort($perRun);                       // newest run id first
            $statuses    = array_column($perRun, 'problem');
            $passes      = count(array_filter($statuses, static fn ($p) => ! $p));
            $problems    = $executions - $passes;
            $passRate    = round(100 * $passes / $executions, 1);

            if ($problems === 0) {
                continue;                          // always green → uninteresting here
            }

            $alternations = 0;
            for ($i = 1; $i < count($statuses); $i++) {
                if ($statuses[$i] !== $statuses[$i - 1]) {
                    $alternations++;
                }
            }
            $isAlternating = $alternations >= 2;

            $kind = null;
            if ($passRate > 0 && $passRate < $this->flakyBelowPassRate && $isAlternating) {
                $kind = 'flaky';
            } elseif ($passes === 0) {
                $kind = 'perma-red';
            } elseif ($passRate < 100 && ! $isAlternating) {
                // failing tail but had history of green → treat as recently broken
                $kind = 'recently-broken';
            }

            if ($kind === null) {
                continue;
            }

            $firstProblemRow = collect(array_values($perRun))->firstWhere('problem', true);

            $results->push([
                'profile'         => $firstProblemRow['profile'],
                'test_identifier' => $firstProblemRow['identifier'],
                'executions'      => $executions,
                'passes'          => $passes,
                'problems'        => $problems,
                'pass_rate'       => $passRate,
                'kind'            => $kind,
                'last_status'     => array_values($perRun)[0]['problem'] ? 'fail' : 'pass',
                'last_problem_message' => $firstProblemRow['message'],
            ]);
        }

        return $results->sortBy([['kind', 'asc'], ['pass_rate', 'asc']])->values();
    }

    public function flakyCountForProfile(string $profile): int
    {
        return $this->detect($profile)->filter(fn ($t) => $t['kind'] === 'flaky')->count();
    }
}
