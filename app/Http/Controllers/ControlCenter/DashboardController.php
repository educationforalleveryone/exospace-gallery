<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Http\Controllers\Controller;
use App\Models\QaTestRun;
use App\Services\TestCenter\TestProfileRegistry;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly TestProfileRegistry $registry,
    ) {}

    /** GET /control-center — status wall: version, latest runs, readiness teaser. */
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

        return view('control-center.overview', [
            'git_commit'  => substr((string) (QaTestRun::whereNotNull('git_commit')->latest('id')->value('git_commit') ?? ''), 0, 7),
            'git_branch'  => QaTestRun::whereNotNull('git_branch')->latest('id')->value('git_branch'),
            'lastActivity'=> optional(QaTestRun::latest('id')->first())->created_at,
            'profiles'    => $profiles,
        ]);
    }

    /** GET /control-center/runs?profile=&status= — history with filters. */
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

    /** GET /control-center/runs/{run} — full detail incl. failures. */
    public function run(QaTestRun $run): View
    {
        $failures = $run->cases()->whereIn('status', ['failed', 'error', 'timed_out'])
            ->orderByDesc('time_ms')->limit(200)->get();

        // For each failing test: last PASS overall + occurrence stats across its profile.
        $identifiers = $failures->pluck('test_identifier')->unique()->values();
        $history = [];
        foreach ($identifiers as $identifier) {
            $rows = \DB::table('qa_test_case_results as c')
                ->join('qa_test_runs as r', 'r.id', '=', 'c.qa_test_run_id')
                ->where('c.test_identifier', $identifier)
                ->where('r.profile', $run->profile)
                ->orderByDesc('r.id')->limit(30)
                ->get(['c.status', 'r.created_at']);

            $passes = $rows->where('status', 'passed')->count();
            $history[$identifier] = [
                'executions'     => $rows->count(),
                'pass_rate'      => $rows->count() > 0 ? round(100 * $passes / $rows->count()) : null,
                'previous_pass'  => optional($rows->firstWhere('status', 'passed'))->created_at,
            ];
        }

        return view('control-center.run-detail', [
            'run'          => $run,
            'failures'     => $failures,
            'history'      => $history,
            'artifactPath' => $this->artifactRelPath($run),
        ]);
    }

    /** GET /control-center/runs/{run}/artifact — stream stored JUnit XML. */
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
