<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Seo\SeoAuditService;
use Illuminate\Console\Command;

/**
 * SEO OS (Iteration 6): scheduled SEO health report.
 *
 *   php artisan exospace:seo-audit
 *
 * Scheduled daily (routes/console.php). Output goes to the log; when the
 * standard OPERATIONAL_ALERT_WEBHOOK is configured and warnings exist, a
 * compact summary is posted to Slack — using the same operational-alert
 * conventions as the existing infra alerts (no new integration needed).
 *
 * This command reports PLATFORM data only. It never fabricates or fetches
 * search-engine data (see docs/MASTER_MANUAL_OPERATIONS.md §3.2).
 */
class SeoAudit extends Command
{
    protected $signature = 'exospace:seo-audit
                            {--slack : Post issues to Slack even when minor (default: only warnings+)}';

    protected $description = 'Run the SEO health audit and report issues';

    public function handle(SeoAuditService $audit): int
    {
        $summary = $audit->summary();
        $issues = $audit->issues();

        // Always log the full report.
        \Illuminate\Support\Facades\Log::channel(config('logging.default'))->info('SEO audit', [
            'summary' => $summary,
            'issues' => $issues,
        ]);

        $this->info('SEO health audit');
        $this->table(['Metric', 'Value'], [
            ['Indexable galleries', (string) $summary['indexable_galleries']],
            ['Indexable artists', (string) $summary['indexable_artists']],
            ['Indexable artworks', (string) $summary['indexable_artworks']],
            ['Published SEO pages', (string) $summary['published_seo_pages']],
            ['Active redirects', (string) $summary['active_redirects']],
        ]);

        if ($issues === []) {
            $this->info('No issues found.');

            return self::SUCCESS;
        }

        $this->warn('Issues:');
        $this->table(['Severity', 'Issue', 'Count'], array_map(fn ($i) => [
            $i['severity'], $i['label'], (string) $i['count'],
        ], $issues));

        $this->maybePostToSlack($summary, $issues);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function maybePostToSlack(array $summary, array $issues): void
    {
        $webhook = (string) config('services.operational_alert_webhook')
            ?: env('OPERATIONAL_ALERT_WEBHOOK');

        if (!$webhook) {
            $this->line('OPERATIONAL_ALERT_WEBHOOK not set — skipping Slack notification.');

            return;
        }

        $hasWarning = collect($issues)->contains(fn ($i) => $i['severity'] === 'warning');
        if (!$hasWarning && !$this->option('slack')) {
            return; // only informational issues — stay quiet by default
        }

        try {
            $lines = [
                '*SEO audit — issues found*',
                sprintf('Indexable: %d galleries · %d artists · %d artworks', $summary['indexable_galleries'], $summary['indexable_artists'], $summary['indexable_artworks']),
                '',
            ];
            foreach ($issues as $issue) {
                $lines[] = sprintf('• [%s] %s — %d', $issue['severity'], $issue['label'], $issue['count']);
            }

            \Illuminate\Support\Facades\Http::timeout(5)->post($webhook, [
                'text' => implode("\n", $lines),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SEO audit: Slack notification failed: ' . $e->getMessage());
        }
    }
}
