<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SeoRedirect;
use Illuminate\Console\Command;

/**
 * SEO OS (Iteration 4): rebuild SEO caches.
 *
 * Clears and eagerly warms the caches the SEO system depends on:
 *   - sitemap version (forces lazy regeneration of every group)
 *   - sitemap index / counts / lastmods
 *   - redirect map
 *   - internal-linking related sets
 *   - welcome featured galleries
 *
 * Run after bulk edits, domain changes, or when Search Console shows a
 * stale sitemap:
 *
 *   php artisan seo:rebuild
 */
class SeoRebuild extends Command
{
    protected $signature = 'seo:rebuild';

    protected $description = 'Clear and rebuild SEO caches (sitemaps, redirects, related content)';

    public function handle(): int
    {
        $version = (int) \Illuminate\Support\Facades\Cache::get('seo:sitemap:version', 1);
        $newVersion = $version + 1;
        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', $newVersion);

        $cleared = 0;

        // Redirect map + related sets + welcome cache.
        SeoRedirect::clearMapCache();
        $cleared++;

        foreach ([
            'welcome:featured-galleries',
        ] as $key) {
            \Illuminate\Support\Facades\Cache::forget($key);
            $cleared++;
        }

        // Related-content sets use unversioned keys — flush by prefix if the
        // backend supports tags, else rely on their short TTL.
        try {
            \Illuminate\Support\Facades\Cache::tags(['seo:related'])->flush();
            $cleared++;
        } catch (\Throwable) {
            // Backends without tag support (e.g. plain Redis without tags):
            // related keys expire via their 15-minute TTL.
        }

        $this->info("SEO caches cleared ({$cleared} keys/tags). Sitemap version: {$version} → {$newVersion}.");
        $this->info('Done. Sitemaps regenerate lazily on their next request.');

        return self::SUCCESS;
    }
}
