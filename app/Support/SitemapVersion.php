<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

class SitemapVersion
{
    public const KEY = 'seo:sitemap:version';

    /**
     * Current public-content generation. Stamped into keys (sitemaps,
     * related content, listing blocks) so a bump rotates every dependent
     * entry at once and old generations expire through their own TTL.
     */
    public static function version(): string
    {
        try {
            return (string) Cache::get(self::KEY, '1');
        } catch (Throwable) {
            // Cache unavailable — callers fall back to live queries.
            return '1';
        }
    }

    /**
     * Rotate to the next generation. Monotonic: concurrent bumps only add.
     */
    public static function bump(): void
    {
        try {
            Cache::add(self::KEY, 1);
            Cache::increment(self::KEY);
        } catch (Throwable $e) {
            // Cache unavailable — versioned entries expire through their
            // TTL instead of failing the mutation that triggered the bump.
            report($e);
        }
    }
}
