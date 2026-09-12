<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SeoRedirect;
use Illuminate\Console\Command;

class SeoRebuild extends Command
{
    protected $signature = 'seo:rebuild';

    protected $description = 'Clear and rebuild SEO caches (sitemaps, redirects, related content)';

    public function handle(): int
    {
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

        try {
            \Illuminate\Support\Facades\Cache::tags(['seo:related'])->flush();
            $cleared++;
        } catch (\Throwable) {
        }

        $this->info("SEO caches cleared ({$cleared} keys/tags). Sitemap version: {$version} → {$newVersion}.");

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
