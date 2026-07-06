<?php

namespace App\Services;

/**
 * M-14: Feature flag service.
 *
 * Provides a simple API for checking whether a feature flag is enabled.
 * Flags are defined in config/feature_flags.php and can be toggled via
 * .env without code changes.
 *
 * Usage:
 *   if (FeatureFlag::isEnabled('subscriptions')) { ... }
 *   @featureFlag('subscriptions') ... @endfeatureFlag
 *   ->middleware('feature_flag:subscriptions')
 */
class FeatureFlag
{
    /**
     * Check if a feature flag is enabled.
     *
     * @param  string $flag  The flag name (must exist in config/feature_flags.php)
     * @return bool          True if the flag is enabled, false otherwise.
     *                       Unknown flags default to false (fail-closed).
     */
    public static function isEnabled(string $flag): bool
    {
        return (bool) config("feature_flags.flags.{$flag}", false);
    }

    /**
     * Check if a feature flag is DISABLED.
     * Convenience method for readability: `FeatureFlag::isDisabled('x')`
     * is clearer than `! FeatureFlag::isEnabled('x')` in some contexts.
     */
    public static function isDisabled(string $flag): bool
    {
        return ! self::isEnabled($flag);
    }

    /**
     * Get all feature flags + their current values.
     * Used by the super-admin dashboard for a "Feature Flags" status panel.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        return array_map('boolval', config('feature_flags.flags', []));
    }
}
