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
        // ITERATION-4 FIX: atomic version bump (seed-then-increment — same
        // pattern as SitemapCacheObserver). The old read-modify-write could
        // race a concurrent observer bump and clobber it back to the same
        // version, producing a no-op "rebuild".
        \Illuminate\Support\Facades\Cache::add('seo:sitemap:version', 1);
        $version = (int) \Illuminate\Support\Facades\Cache::get('seo:sitemap:version', 1);
        \Illuminate\Support\Facades\Cache::increment('seo:sitemap:version');
        $newVersion = $version + 1;

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

        // ITERATION-4: actually WARM the fresh-version sitemap keys (10
        // pages per group keeps this admin-triggered synchronous call
        // fast). The docblock has claimed "eagerly warms" since Iteration 4
        // of the SEO OS while the implementation only bumped the version
        // and left Googlebot to pay the cold rebuild — now the claim is
        // true. The daily 04:15 sitemap:warm run covers the rest.
        try {
            $stats = app(\App\Http\Controllers\SitemapController::class)
                ->warmCaches(null, 10);
            $this->info("Sitemap caches warmed: {$stats['warmed']} keys under v{$newVersion}.");
        } catch (\Throwable $e) {
            $this->warn('Sitemap warming failed (sitemaps regenerate lazily on next request): ' . $e->getMessage());
        }

        return self::SUCCESS;
    }
}
