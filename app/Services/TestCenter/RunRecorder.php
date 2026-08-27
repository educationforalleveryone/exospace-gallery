<?php

declare(strict_types=1);

namespace App\Services\TestCenter;

use App\Models\QaTestCaseResult;
use App\Models\QaTestRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Persists a run record + per-case results from a parsed JUnit artifact.
 *
 * Honesty contract: aggregates written here ALWAYS come from the artifact —
 * this class never invents numbers. A run whose totals say zero cases is
 * recorded as NOT EXECUTED with the provided reason, never as "passed".
 */
class RunRecorder
{
    public function __construct(
        private readonly JunitParser $parser = new JunitParser(),
    ) {}

    /**
     * Import (or record) a finished run.
     *
     * @param  array<string,mixed> $metadata  profile, environment, safety, trigger,
     *                                        git_commit, git_branch, git_tag, app_version, runner,
     *                                        ci_run_url, db_driver, php_version, blocked_reason, meta
     * @param  string|null         $junitPath absolute path to JUnit XML (null when blocked/not executed)
     * @param  array{status?:string, blocked_reason?:string, started_at?:\Carbon\CarbonInterface, finished_at?:\Carbon\CarbonInterface, duration_ms?:int} $overrides
     */
    public function record(array $metadata, ?string $junitPath, array $overrides = []): QaTestRun
    {
        $parsed = null;

        if ($junitPath !== null) {
            $parsed = $this->parser->parseFile($junitPath);
        }

        $totals = $parsed['totals'] ?? null;
        $cases  = $parsed['cases'] ?? [];

        $status = $overrides['status']
            ?? $this->inferStatus($metadata, $totals);

        return DB::transaction(function () use ($metadata, $junitPath, $cases, $totals, $status, $overrides) {

            // Archive the artifact for later forensics (never store secrets inside).
            $artifactRelPath = null;
            if ($junitPath && is_file($junitPath)) {
                $disk = Storage::disk('local');
                $artifactRelPath = trim(config('test-center.artifact_disk'), '/').'/'.$metadata['profile'].'/'.now()->format('YmdHis').'-'.bin2hex(random_bytes(3)).'.xml';
                $disk->put($artifactRelPath, file_get_contents($junitPath));
            }

            /** @var QaTestRun $run */
            $run = QaTestRun::create([
                'uuid'          => (string) \Illuminate\Support\Str::uuid(),
                'profile'       => $metadata['profile'],
                'environment'   => $metadata['environment'] ?? 'local',
                'safety'        => $metadata['safety'] ?? 'test-only',
                'trigger'       => $metadata['trigger'] ?? 'manual',
                'runner'        => $metadata['runner'] ?? null,
                'git_commit'    => $metadata['git_commit'] ?? $this->detectGitCommit(),
                'git_branch'    => $metadata['git_branch'] ?? $this->detectGitBranch(),
                'git_tag'       => $metadata['git_tag'] ?? null,
                'app_version'   => $metadata['app_version'] ?? null,
                'ci_run_url'    => $metadata['ci_run_url'] ?? null,
                'status'        => $status,
                'blocked_reason'=> $overrides['blocked_reason'] ?? $metadata['blocked_reason'] ?? null,
                'started_at'    => $overrides['started_at'] ?? now(),
                'finished_at'   => $overrides['finished_at'] ?? now(),
                'duration_ms'   => $overrides['duration_ms'] ?? null,

                'total'         => $totals['tests']      ?? 0,
                'passed'        => max(0, ($totals['tests'] ?? 0) - ($totals['failures'] ?? 0) - ($totals['errors'] ?? 0) - ($totals['skipped'] ?? 0) - ($totals['warnings'] ?? 0)),
                'failed'        => $totals['failures']   ?? 0,
                'errored'       => $totals['errors']     ?? 0,
                'skipped'       => $totals['skipped']    ?? 0,
                'timed_out'     => 0,                     // enriched by intelligence pass in iteration 3

                'assertions'    => $totals['assertions'] ?? 0,
                'db_driver'     => $metadata['db_driver'] ?? config('database.default'),
                'php_version'   => $metadata['php_version'] ?? PHP_VERSION,
                'meta'          => array_merge($metadata['meta'] ?? [], [
                    'artifact_path' => $artifactRelPath,
                    'junit_time'    => $totals['time'] ?? null,
                    'warnings'      => $totals['warnings'] ?? 0,
                ]),
            ]);

            if ($cases !== []) {
                $rows = [];
                foreach ($cases as $case) {
                    $rows[] = [
                        'qa_test_run_id'  => $run->id,
                        'test_identifier' => mb_substr($case['identifier'], 0, 500),
                        'classname'       => mb_substr($case['classname'], 0, 190),
                        'method_name'     => mb_substr($case['name'], 0, 190),
                        'data_set'        => $case['data_set'],
                        'status'          => $case['status'],
                        'time_ms'         => $case['time_ms'],
                        'message'         => $case['message'],
                        'detail'          => $case['detail'],
                        'exception_class' => mb_substr((string) $case['exception_class'], 0, 180) ?: null,
                    ];
                }
                foreach (array_chunk($rows, 250) as $chunk) {
                    DB::table((new QaTestCaseResult)->getTable())->insert($chunk);
                }
            }

            // Run-level failure classification: infrastructure if EVERY problem case is infra.
            $run->refresh();
            $this->classifyRun($run);

            return $run;
        });
    }

    private function inferStatus(array $metadata, ?array $totals): string
    {
        if (! empty($metadata['blocked_reason'])) {
            return QaTestRun::STATUS_BLOCKED;
        }

        $caseCount = $totals['tests'] ?? 0;

        // Zero executed tests must never read as "passed".
        if ($caseCount === 0) {
            return QaTestRun::STATUS_NOT_EXECUTED;
        }

        $problems = ($totals['failures'] ?? 0) + ($totals['errors'] ?? 0) + ($totals['warnings'] ?? 0);

        return $problems > 0
            ? QaTestRun::STATUS_FAILED
            : QaTestRun::STATUS_PASSED;
    }

    private function classifyRun(QaTestRun $run): void
    {
        $problemCases = $run->failures()->get(['id', 'status', 'message', 'detail', 'exception_class']);

        if ($problemCases->isEmpty()) {
            $run->forceFill(['failure_class' => null])->saveQuietly();

            return;
        }

        $classes = $problemCases
            ->map(fn ($c) => (new QaTestCaseResult)->forceFill($c->toArray())->failureClass());

        $infra = $classes->filter(fn ($c) => $c === 'infrastructure')->count();
        $app   = $classes->filter(fn ($c) => $c === 'application')->count();

        $class = match (true) {
            $app === 0 && $infra > 0  => 'infrastructure',
            $infra === 0 && $app > 0  => 'application',
            default                   => 'mixed',
        };

        $run->forceFill(['failure_class' => $class])->saveQuietly();
    }

    private function detectGitCommit(): ?string
    {
        $commit = $this->gitCommand('rev-parse --verify HEAD');

        return $commit !== null ? substr($commit, 0, 40) : null;
    }

    private function detectGitBranch(): ?string
    {
        return $this->gitCommand('rev-parse --abbrev-ref HEAD') ?: $this->gitCommand('branch --show-current');
    }

    private function gitCommand(string $args): ?string
    {
        $base = base_path();

        if (! is_dir($base.'/.git')) {
            return null;
        }

        $output = @shell_exec('cd '.escapeshellarg($base).' && git '.$args.' 2>/dev/null');

        return is_string($output) ? trim($output) ?: null : null;
    }
}
