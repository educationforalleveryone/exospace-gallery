<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\SitemapController;
use Illuminate\Console\Command;

class WarmSitemap extends Command
{
    protected $signature = 'sitemap:warm
                            {--group= : Warm a single group (static|galleries|artists|artworks|content)}
                            {--max-pages=25 : Safety cap on sub-sitemap pages warmed per group}';

    protected $description = 'Pre-populate sitemap cache keys so crawler requests never pay the cold-rebuild cost.';

    public function handle(SitemapController $sitemap): int
    {
        $group = $this->option('group');
        $maxPages = max(1, (int) $this->option('max-pages'));

        $validGroups = ['static', 'galleries', 'artists', 'artworks', 'events', 'content'];

        if ($group !== null && $group !== '' && ! in_array($group, $validGroups, true)) {
            $this->error("Unknown group '{$group}'. Valid: " . implode(', ', $validGroups) . '.');
            return self::FAILURE;
        }

        $started = microtime(true);
        $stats = $sitemap->warmCaches(($group ?: null), $maxPages);
        $elapsed = round((microtime(true) - $started) * 1000);

        foreach ($stats['groups'] as $name => $pages) {
            $this->line("  <info>warmed</info> {$name}: {$pages} page(s)");
        }
        $this->info("Sitemap caches warmed: {$stats['warmed']} keys in {$elapsed}ms (version v" . (int) \Illuminate\Support\Facades\Cache::get('seo:sitemap:version', 1) . ')');

        if ($stats['capped']) {
            $this->warn("  Page cap ({$maxPages}) reached for at least one group — deeper pages stay lazy-warmed.");
        }

        app(\App\Services\JobHeartbeatService::class)->stamp('sitemap:warm');

        return self::SUCCESS;
    }
}
