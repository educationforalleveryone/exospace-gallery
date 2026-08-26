<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\SitemapController;
use Illuminate\Console\Command;

/**
 * ITERATION 4 — sitemap cache warming.
 *
 * Why this exists: a cold sitemap cache key rebuilds synchronously inside
 * the CRAWLER's request (Cache::flexible's cold path computes inline, with
 * no lock). At scale that means Googlebot pays a multi-second quality-gate
 * COUNT + up-to-2,000-row query + Blade XML render, and any entity write
 * (observer version bump) re-colds every key at once. A bulk upload of 200
 * artworks used to leave the crawler path rebuilding all day.
 *
 * What it does: pre-populates every sitemap cache key — index, per-group
 * counts + lastmods, group sub-sitemap pages, and the RSS feed — inside a
 * scheduled window (daily 04:15, before the 04:30 seo:audit health check).
 * Crawler requests become pure cache reads.
 *
 * Design notes:
 *   - Key/TTL construction is NOT duplicated here: warmCaches() on
 *     SitemapController routes through the same private cache accessors
 *     the request path uses, so a warmed key can never diverge from the
 *     key a crawler would read.
 *   - --max-pages caps work per group (default 25 pages × 2,000 URLs =
 *     50k URLs per group); deeper pages stay lazy. Run with a higher cap
 *     manually if needed.
 *   - The controller has no constructor dependencies, so container
 *     resolution is trivial and stateless.
 */
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

        if ($group !== null && $group !== '' && ! in_array($group, ['static', 'galleries', 'artists', 'artworks', 'content'], true)) {
            $this->error("Unknown group '{$group}'. Valid: static, galleries, artists, artworks, content.");
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

        return self::SUCCESS;
    }
}
