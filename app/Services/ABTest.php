<?php

namespace App\Services;

use Illuminate\Support\Facades\Session;

class ABTest
{
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

    public static function isVariant(string $experiment, string $variant): bool
    {
        return self::variant($experiment) === $variant;
    }

    public static function all(): array
    {
        return config('abtests.experiments', []);
    }
}
