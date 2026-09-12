<?php

namespace App\Services;

class FeatureFlag
{
    public static function isEnabled(string $flag): bool
    {
        return (bool) config("feature_flags.flags.{$flag}", false);
    }

    public static function isDisabled(string $flag): bool
    {
        return ! self::isEnabled($flag);
    }

    public static function all(): array
    {
        return array_map('boolval', config('feature_flags.flags', []));
    }
}
