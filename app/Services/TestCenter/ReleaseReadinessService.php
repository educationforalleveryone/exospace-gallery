<?php

declare(strict_types=1);

namespace App\Services\TestCenter;

use App\Models\QaTestRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Evaluates config/release-gates.php into a concrete ship/no-ship verdict.
 *
 * Philosophy encoded here (mirrors MASTER_MANUAL):
 *  - A missing/never-run blocking profile = NOT proven = BLOCKED.
 *  - Advisory failures warn but never block.
 *  - Runs are pinned to a freshness window so last month's green cannot
 *    vouch for today's build.
 */
class ReleaseReadinessService
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('release-gates', []);
    }

    /**
     * @return array{verdict:string, summary:array, gates:Collection, evaluated_at:Carbon}
     */
    public function evaluate(string $environment = 'production'): array
    {
        $envConfig = ($this->config['environments'] ?? [])[$environment] ?? null;

        if ($envConfig === null) {
            return [
                'verdict'      => 'unproven',
                'summary'      => ['blocking' => 0, 'advisory_failing' => 0, 'passing' => 0, 'total_gates' => 0, 'reasons' => ["No release gate configuration for [{$environment}]."]],
                'gates'        => collect(),
                'evaluated_at' => now(),
            ];
        }

        $freshnessHours = (int) ($this->config['freshness_hours'] ?? 48);
        $gates          = collect();
        $reasons        = [];
        $blockingFail   = 0;
        $advisoryFail   = 0;
        $passingCount   = 0;
        $totalGates     = count($envConfig['gates'] ?? []);

        foreach (($envConfig['gates'] ?? []) as $key => $gate) {
            $profileKey  = $gate['profile'] ?? $key;
            $requirePass = (bool) ($gate['require_passed'] ?? true);
            // Freshness rules: no key ⇒ global freshness window applies;
            // explicit null ⇒ never expires (build gate is re-proven every push).
            $hasExplicitKey = array_key_exists('max_age_hours', $gate);
            $expires        = ! ($hasExplicitKey && $gate['max_age_hours'] === null);
            $maxAgeHrs      = (float) ($hasExplicitKey ? $gate['max_age_hours'] : ($this->config['freshness_hours'] ?? 48));
            $isBlocking     = ($gate['mode'] ?? 'blocking') === 'blocking';

            /** @var QaTestRun|null $run */
            $run = $this->latestQualifyingRun($profileKey);

            if ($run === null) {
                $everRan = QaTestRun::where('profile', $profileKey)->exists();
                $state   = 'missing';
                $note    = $everRan
                    ? 'No run inside the freshness window — evidence is stale.'
                    : 'Never executed/imported through the Control Center.';
            } else {
                $ageHrs = (float) abs($run->created_at->diffInHours(now(), false));

                if ($expires && $ageHrs > $maxAgeHrs) {
                    $state = 'stale';
                    $note  = sprintf('Newest run is %.1fh old (limit %gh). Re-run to re-prove.', $ageHrs, $maxAgeHrs);
                } elseif (! $requirePass) {
                    $state = 'satisfied';
                    $note  = sprintf('Executed %dh ago (%s).', (int) $ageHrs, $run->displayStatus());
                } else {
                    $state = $run->status === QaTestRun::STATUS_PASSED ? 'satisfied' : 'failing';
                    $note  = sprintf(
                        '%s · %d/%d green · %dh ago%s',
                        strtoupper((string) $run->displayStatus()),
                        $run->passed,
                        $run->total,
                        (int) $ageHrs,
                        $run->failure_class !== null ? " · class: {$run->failure_class}" : ''
                    );
                }
            }

            $verdict = match ($state) {
                'satisfied'                 => 'green',
                'stale', 'missing', 'failing' => $isBlocking ? 'red-blocking' : 'amber-advisory',
                default                     => 'amber-advisory',
            };

            if ($verdict === 'green') {
                $passingCount++;
            } elseif ($verdict === 'red-blocking') {
                $blockingFail++;
                $reasons[] = "[{$gate['label']}] {$note}";
            } else {
                $advisoryFail++;
                $reasons[] = "advisory [{$gate['label']}] {$note}";
            }

            $gates->put($key, [
                'label'       => $gate['label'],
                'profile'     => $profileKey,
                'mode'        => $isBlocking ? 'blocking' : 'advisory',
                'state'       => $state,
                'verdict'     => $verdict,
                'note'        => $note,
                'run_id'      => $run?->id,
                'environment' => $run?->environment,
                'created_at'  => $run?->created_at,
            ]);
        }

        $overallVerdict = 'unproven';

        if ($totalGates > 0) {
            if ($blockingFail > 0) {
                $overallVerdict = 'blocked';
            } elseif ($passingCount === $totalGates && $advisoryFail === 0) {
                $overallVerdict = 'ready';
            } else {
                // Everything blocking satisfied; only advisories outstanding.
                $overallVerdict = $passingCount + $advisoryFail === $totalGates ? 'ready-with-warnings' : 'unproven';
            }
        }

        return [
            'verdict'      => $overallVerdict,
            'summary'      => ['blocking' => $blockingFail, 'advisory_failing' => $advisoryFail, 'passing' => $passingCount, 'total_gates' => $totalGates, 'reasons' => $reasons],
            'gates'        => $gates,
            'evaluated_at' => now(),
        ];
    }

    /**
     * Most recent run for a profile. `ci_build` is a synthetic gate fed by ANY
     * ci-triggered artifact arrival within freshness (the build pipeline posts
     * status through ingest even when it only lints/builds).
     */
    private function latestQualifyingRun(string $profile): ?QaTestRun
    {
        if ($profile === 'ci_build') {
            return QaTestRun::where('trigger', 'ci')->orderByDesc('id')->first();
        }

        return QaTestRun::where('profile', $profile)->orderByDesc('id')->first();
    }
}
