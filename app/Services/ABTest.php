<?php

namespace App\Services;

use Illuminate\Support\Facades\Session;

/**
 * M-15: A/B testing service.
 *
 * Assigns users to experiment variants deterministically based on a hash
 * of their session ID. The same user always sees the same variant within
 * a session (and across sessions if the session ID is stable).
 *
 * Usage:
 *   $variant = ABTest::variant('pricing_cta');  // 'A' or 'B'
 *   @abtest('pricing_cta') ... @endabtest
 *
 * Experiments are defined in config/abtests.php. To disable an experiment,
 * remove it from the config (or empty its variants array) — all users
 * will then get variant 'A' (the control).
 */
class ABTest
{
    /**
     * Get the variant for the current user for a given experiment.
     *
     * @param  string $experiment  The experiment name (must exist in config/abtests.php)
     * @return string              The variant name ('A', 'B', 'C', ...). Returns 'A' if
     *                             the experiment doesn't exist or is disabled.
     */
    public static function variant(string $experiment): string
    {
        $experiments = config('abtests.experiments', []);

        // Unknown or disabled experiment → always variant A (control)
        if (! isset($experiments[$experiment]) || empty($experiments[$experiment]['variants'])) {
            return 'A';
        }

        $variants = $experiments[$experiment]['variants'];

        // Check if the user is already assigned (session-based persistence)
        $sessionKey = "abtest:{$experiment}";
        $assigned = Session::get($sessionKey);

        if ($assigned && isset($variants[$assigned])) {
            return $assigned;
        }

        // Assign based on a hash of the session ID (deterministic per session)
        $sessionId = Session::getId();
        $hash = crc32($experiment . $sessionId);
        $bucket = ($hash % 100) + 1; // 1-100

        $cumulative = 0;
        foreach ($variants as $name => $percentage) {
            $cumulative += $percentage;
            if ($bucket <= $cumulative) {
                Session::put($sessionKey, $name);
                return $name;
            }
        }

        // Fallback (shouldn't happen if percentages sum to 100)
        return 'A';
    }

    /**
     * Check if the current user is in a specific variant of an experiment.
     *
     * @param  string $experiment
     * @param  string $variant
     * @return bool
     */
    public static function isVariant(string $experiment, string $variant): bool
    {
        return self::variant($experiment) === $variant;
    }

    /**
     * Get all experiments + their variants (for admin/debug display).
     *
     * @return array
     */
    public static function all(): array
    {
        return config('abtests.experiments', []);
    }
}
