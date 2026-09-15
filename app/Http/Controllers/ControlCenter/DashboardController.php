<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Http\Controllers\Controller;
use App\Models\QaTestRun;
use App\Services\TestCenter\FlakyDetector;
use App\Services\TestCenter\QaNotifier;
use App\Services\TestCenter\ReleaseReadinessService;
use App\Services\TestCenter\TestProfileRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly TestProfileRegistry $registry,
        private readonly ReleaseReadinessService $readiness,
        private readonly FlakyDetector $flaky,
    ) {}

    public function overview(): View
    {
        $profiles = [];

        foreach ($this->registry->summarizeForList() as $key => $meta) {
            $profiles[$key] = $meta + [
                'latest_run' => QaTestRun::where('profile', $key)
                    ->orderByDesc('id')->first(),
                'history'    => QaTestRun::select('id', 'status', 'passed', 'failed', 'errored', 'created_at')
                    ->where('profile', $key)->orderByDesc('id')->limit(5)->get()
                    ->reverse()->values(),
            ];
        }

        // Release readiness — evaluate + notify once per verdict hash.
        $readiness = $this->readiness->evaluate('production');
        $hash      = md5(json_encode([$readiness['verdict'], $readiness['summary']['reasons']]));

        if ($readiness['verdict'] === 'blocked' && Cache::get('qa:last-release-verdict-hash') !== $hash) {
            try {
                app(QaNotifier::class)->releaseBlocked($hash, $readiness['summary']);
                Cache::put('qa:last-release-verdict-hash', $hash, now()->addDay());
            } catch (\Throwable) { /* notification must never break the page */ }
        }

        return view('control-center.overview', [
            'git_commit'   => substr((string) (QaTestRun::whereNotNull('git_commit')->latest('id')->value('git_commit') ?? ''), 0, 7),
            'git_branch'   => QaTestRun::whereNotNull('git_branch')->latest('id')->value('git_branch'),
            'lastActivity' => optional(QaTestRun::latest('id')->first())->created_at,
            'profiles'     => $profiles,
            'readiness'    => $readiness,
            'flaky'        => $this->flaky->detect(),
        ]);
    }

    public function flaky(): View
    {
        return view('control-center.flaky', [
            'tests' => $this->flaky->detect(request('profile')),
            'profiles' => array_map(fn ($m) => $m['label'], $this->registry->summarizeForList()),
        ]);
    }

    public function runs(): View
    {
        $query = QaTestRun::query()->orderByDesc('id');

        if ($profile = request('profile')) {
            $query->where('profile', $profile);
        }
        if ($status = request('status')) {
            $query->where('status', $status);
        }

        return view('control-center.runs', [
            'runs'     => $query->paginate(25)->withQueryString(),
            'profiles' => array_map(fn ($m) => $m['label'], $this->registry->summarizeForList()),
        ]);
    }

    public function run(QaTestRun $run): View
    {
        $failures = $run->cases()->whereIn('status', ['failed', 'error', 'timed_out'])
            ->orderByDesc('time_ms')->limit(200)->get();

        // For each failing test: last PASS overall + occurrence stats across
        // its profile — one windowed query instead of one query per failure.
        $identifiers = $failures->pluck('test_identifier')->unique()->values();
        $history = [];

        if ($identifiers->isNotEmpty()) {
            // Windowed latest-30-rows per test identifier, executed as one
            // query. The window function must live in a derived table — it
            // cannot be filtered directly in a WHERE clause.
            $idPlaceholders = implode(',', array_fill(0, $identifiers->count(), '?'));
            $rows = collect(\DB::select(
                "SELECT test_identifier, status, created_at FROM (
                    SELECT c.test_identifier, c.status, r.created_at,
                           ROW_NUMBER() OVER (PARTITION BY c.test_identifier ORDER BY r.id DESC) AS rn
                    FROM qa_test_case_results c
                    INNER JOIN qa_test_runs r ON r.id = c.qa_test_run_id
                    WHERE r.profile = ? AND c.test_identifier IN ({$idPlaceholders})
                ) w
                WHERE w.rn <= 30",
                [$run->profile, ...$identifiers->all()],
            ));

            foreach ($identifiers as $identifier) {
                $testRows = $rows->where('test_identifier', $identifier)->values();
                $passes = $testRows->where('status', 'passed')->count();
                $history[$identifier] = [
                    'executions'     => $testRows->count(),
                    'pass_rate'      => $testRows->count() > 0 ? round(100 * $passes / $testRows->count()) : null,
                    'previous_pass'  => optional($testRows->firstWhere('status', 'passed'))->created_at,
                ];
            }
        }

        return view('control-center.run-detail', [
            'run'          => $run,
            'failures'     => $failures,
            'history'      => $history,
            'artifactPath' => $this->artifactRelPath($run),
        ]);
    }

    public function artifact(QaTestRun $run): Response
    {
        $rel = $this->artifactRelPath($run);

        abort_unless($rel !== null && Storage::disk('local')->exists($rel), 404);

        return Storage::disk('local')->download($rel, "qa-run-{$run->id}-junit.xml");
    }

    private function artifactRelPath(QaTestRun $run): ?string
    {
        return data_get($run->meta, 'artifact_path');
    }
}
