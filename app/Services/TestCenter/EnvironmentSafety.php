<?php

declare(strict_types=1);

namespace App\Services\TestCenter;

use App\Models\QaTestRun;

/**
 * Environment safety guard — the single choke point that decides whether a
 * profile may execute against a given target environment.
 *
 * RULES ENCODED HERE (mirrors config/test-center.php):
 *   - PHPUnit-style suites NEVER run against production.
 *   - Destructive strategies never run against production or staging
 *     unless staging explicitly opted in via TEST_CENTER_STAGING_SUITES.
 *   - prod-safe-read profiles may run anywhere.
 */
class EnvironmentSafety
{
    public function __construct(
        ?array $config = null,
    ) {
        $this->config = $config ?: config('test-center', []);
    }

    /**
     * @return bool true when execution is permitted.
     */
    public function mayExecute(string $profileKey, array $profile, string $targetEnvironment): bool
    {
        $verdict = $this->evaluate($profileKey, $profile, $targetEnvironment);

        return $verdict['allowed'];
    }

    /**
     * Structured verdict consumed by CLI, dashboard and ingest API so every
     * surface renders the SAME explanation for a refusal.
     *
     * @return array{allowed:bool, reason:?string, remediation:?string}
     */
    public function evaluate(string $profileKey, array $profile, string $targetEnvironment): array
    {
        $environments = $this->config['environments'] ?? [];
        $safety       = $profile['safety'] ?? 'test-only';

        if (! isset($environments[$targetEnvironment])) {
            return [
                'allowed'    => false,
                'reason'     => "Unknown target environment [{$targetEnvironment}].",
                'remediation'=> 'Use one of: '.implode(', ', array_keys($environments)).'.',
            ];
        }

        if ($targetEnvironment === ($profile['target_environments'] ?? null) && ! empty($profile['target_environments'])) {
            // explicitly allowed target list wins early
            return ['allowed' => true, 'reason' => null, 'remediation' => null];
        }

        if (! empty($profile['target_environments']) && ! in_array($targetEnvironment, (array) $profile['target_environments'], true)) {
            return [
                'allowed'    => false,
                'reason'     => "Profile [{$profileKey}] targets ".implode('/', (array) $profile['target_environments']).", not {$targetEnvironment}.",
                'remediation'=> null,
            ];
        }

        $allowedForClass = $this->config['safety_classes'][$safety]['allowed_environments'] ?? [];

        if (! in_array($targetEnvironment, $allowedForClass, true)) {
            $lockdown = $targetEnvironment === 'production'
                ? '🔒 Production is protected: destructive suites can never be executed here.'
                : "Safety class [{$safety}] is not permitted on [{$targetEnvironment}].";

            return [
                'allowed'    => false,
                'reason'     => $lockdown.' Profile "'.$profileKey.'" requires an isolated runner (local or CI).',
                'remediation'=> $targetEnvironment === 'production'
                    ? 'Run this profile through GitHub Actions (.github/workflows/test-profiles.yml → Run workflow) or your local machine.'
                    : 'Enable it for this environment in config/test-center.php only if you accept the blast radius.',
            ];
        }

        // Suite execution vs environment capability (staging gating).
        $envAllowsSuites = $environments[$targetEnvironment]['allow_suite_execution'] ?? false;
        $needsDatabase   = in_array(($profile['database'] ?? null), ['sqlite', 'mysql', 'mysql-required'], true);

        if ($needsDatabase && ! $envAllowsSuites && $safety !== 'prod-safe-read') {
            return [
                'allowed'    => false,
                'reason'     => "Environment [{$targetEnvironment}] does not allow suite execution (it would rebuild/mutate its data store).",
                'remediation'=> $targetEnvironment === 'staging'
                    ? 'Set TEST_CENTER_STAGING_SUITES=true ONLY if staging data is disposable.'
                    : 'Run against local or CI instead.',
            ];
        }

        return ['allowed' => true, 'reason' => null, 'remediation' => null];
    }

    /** Helper used by qa:run when a profile declares database=mysql-required. */
    public function databaseRequirement(array $profile): ?string
    {
        return $profile['database'] ?? null;
    }

    public function statusBadge(QaTestRun $run): string
    {
        $envs = $this->config['environments'][$run->environment]['badge'] ?? 'slate';

        return $run->badgeColor().'-'.$envs;
    }
}
