<?php

declare(strict_types=1);

namespace App\Services\TestCenter;

use App\Models\QaTestRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Executes the safe-read probe strategies (http-smoke / in-process-checks)
 * through their console commands' junit-json contract, converts the payload
 * into JUnit XML, and records an honest run row.
 *
 * Shared by:
 *   - `qa:run smoke` / `qa:run production_health` (strategy dispatch)
 *   - Control Center start buttons (synchronous execution, seconds-long)
 */
class ProbeRunner
{
    public function __construct(
        private readonly TestProfileRegistry $registry,
        private readonly EnvironmentSafety $safety,
        private readonly RunRecorder $recorder,
        private readonly JunitParser $parser,
    ) {}

    /**
     * @param  string $targetEnv one of local|ci|staging|production
     *
     * @return array{run:QaTestRun, success:bool}
     */
    public function execute(string $profileKey, string $targetEnv): array
    {
        $profile = $this->registry->profile($profileKey);

        $verdict = $this->safety->evaluate($profileKey, $profile, $targetEnv);
        if (! $verdict['allowed']) {
            $run = $this->recorder->record([
                'profile'        => $profileKey,
                'environment'    => $targetEnv,
                'safety'         => $profile['safety'] ?? 'prod-safe-read',
                'trigger'        => 'api',
                'blocked_reason' => $verdict['reason'],
            ], null, ['status' => QaTestRun::STATUS_BLOCKED]);

            return ['run' => $run, 'success' => false];
        }

        [$command, $params] = match ($profile['strategy'] ?? '') {
            'http-smoke'        => ['qa:smoke', ['--target-env' => $targetEnv]],
            'in-process-checks' => ['qa:health', []],
            default             => throw new \LogicException("Profile [{$profileKey}] has no executable strategy."),
        };

        $started = microtime(true);

        $params['--format'] = 'junit-json';
        \Illuminate\Support\Facades\Artisan::call($command, $params);
        $json = trim(Artisan::output());

        $payload = json_decode($json, true);

        if (! is_array($payload) || ! isset($payload['totals'])) {
            $run = $this->recorder->record([
                'profile'        => $profileKey,
                'environment'    => $targetEnv,
                'safety'         => $profile['safety'] ?? 'prod-safe-read',
                'trigger'        => 'manual',
                'blocked_reason' => "{$command} did not honour the junit-json contract.",
            ], null, ['status' => QaTestRun::STATUS_NOT_EXECUTED]);

            return ['run' => $run, 'success' => false];
        }

        $artifactPath = storage_path('framework/qa/'.$profileKey.'-'.now()->format('YmdHis').'-'.Str::random(4).'.xml');
        @mkdir(dirname($artifactPath), 0775, true);
        file_put_contents($artifactPath, $this->buildJunitXml($profileKey, $payload));

        $totals   = $this->parser->parseFile($artifactPath)['totals'];
        $problems = $totals['failures'] + $totals['errors'];
        $status   = match (true) {
            $totals['tests'] === 0   => QaTestRun::STATUS_NOT_EXECUTED,
            $problems === 0          => QaTestRun::STATUS_PASSED,
            default                  => QaTestRun::STATUS_FAILED,
        };

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $run = $this->recorder->record([
            'profile'     => $profileKey,
            'environment' => $targetEnv,
            'safety'      => $profile['safety'] ?? 'prod-safe-read',
            'trigger'     => 'manual',
            'runner'      => PHP_SAPI,
            'meta'        => [
                'probe_command' => $command,
                'target_url'    => config("test-center.environments.{$targetEnv}.base_url") ?? config('app.url'),
            ],
        ], $artifactPath, [
            'status'      => $status,
            'started_at'  => now()->subMilliseconds($durationMs),
            'finished_at' => now(),
            'duration_ms' => $durationMs,
        ]);

        return ['run' => $run, 'success' => $status === QaTestRun::STATUS_PASSED];
    }

    private function buildJunitXml(string $suiteName, array $payload): string
    {
        $t        = $payload['totals'];
        $casesXml = '';

        foreach (($payload['cases'] ?? []) as $case) {
            $inner = '';
            $msg   = htmlspecialchars((string) ($case['message'] ?? ''), ENT_XML1);

            $inner = match ($case['status']) {
                'skipped'              => "<skipped>{$msg}</skipped>",
                'passed', null, ''     => '',
                default                => "<failure type=\"AssertionFailed\">{$msg}</failure>",
            };

            $casesXml .= sprintf(
                '<testcase name="%s" classname="%s" time="0">%s</testcase>',
                htmlspecialchars((string) $case['name'], ENT_XML1),
                htmlspecialchars((string) $case['classname'], ENT_XML1),
                $inner
            );
        }

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="%s" tests="%d" assertions="%d" failures="%d" errors="0" skipped="%d" time="%s">%s</testsuite></testsuites>',
            htmlspecialchars($suiteName, ENT_XML1),
            (int) ($t['tests'] ?? 0),
            (int) ($t['assertions'] ?? 0),
            (int) ($t['failures'] ?? 0),
            (int) ($t['skipped'] ?? 0),
            (string) round(($payload['duration_ms'] ?? 0) / 1000, 3),
            $casesXml
        );
    }
}
