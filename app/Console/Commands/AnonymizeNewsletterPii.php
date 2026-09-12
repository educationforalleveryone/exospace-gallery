<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AnonymizeNewsletterPii extends Command
{
    protected $signature = 'exospace:anonymize-newsletter-pii
                            {--retention-months=18 : Anonymize PII on signups older than this many months}
                            {--dry-run : Show what would be anonymized without executing}
                            {--batch-size=500 : Rows per batch}';

    protected $description = 'Anonymize PII (name, email, ip_address, referrer) on old newsletter_signups rows (GDPR retention).';

    public function handle(): int
    {
        $retentionMonths = (int) $this->option('retention-months');
        $dryRun          = (bool) $this->option('dry-run');
        $batchSize       = (int) $this->option('batch-size');

        $cutoff = now()->subMonths($retentionMonths);

        $this->info("Newsletter signup PII anonymization for records older than {$retentionMonths} months (before {$cutoff->toDateString()})");
        $this->info("  Dry run: " . ($dryRun ? 'YES' : 'NO'));
        $this->info("  Batch size: {$batchSize}");
        $this->newLine();

        if (! Schema::hasTable('newsletter_signups')) {
            $this->warn("  newsletter_signups table does not exist — skipping.");
            return self::SUCCESS;
        }

        $needsAnonymization = DB::table('newsletter_signups')
            ->where('signed_up_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('email', 'not like', 'anonymized:%')
                  ->orWhereNotNull('name')
                  ->orWhereNotNull('ip_address')
                  ->orWhereNotNull('referrer');
            })
            ->count();

        if ($needsAnonymization === 0) {
            $this->info("  No newsletter signup rows need anonymization (all old rows already anonymized).");
            return self::SUCCESS;
        }

        $this->info("  Found {$needsAnonymization} newsletter signup rows needing anonymization.");

        if ($dryRun) {
            $this->warn("  [DRY-RUN] Would anonymize {$needsAnonymization} newsletter signup rows. No changes made.");
            return self::SUCCESS;
        }

        $anonymized = 0;
        $appId = config('app.key');

        DB::table('newsletter_signups')
            ->where('signed_up_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('email', 'not like', 'anonymized:%')
                  ->orWhereNotNull('name')
                  ->orWhereNotNull('ip_address')
                  ->orWhereNotNull('referrer');
            })
            ->orderBy('id')
            ->chunkById($batchSize, function ($rows) use ($appId, &$anonymized) {
                foreach ($rows as $row) {
                    DB::table('newsletter_signups')
                        ->where('id', $row->id)
                        ->update([
                            'email'      => 'anonymized:' . substr(hash('sha256', $appId . $row->email), 0, 16),
                            'name'       => null,
                            'ip_address' => null,
                            'referrer'   => null,
                            'updated_at' => now(),
                        ]);
                    $anonymized++;
                }

                $this->info("  Anonymized batch (running total: {$anonymized})");
            });

        $this->info("  Anonymized {$anonymized} newsletter signup rows.");

        Log::info('AnonymizeNewsletterPii: complete', [
            'anonymized'        => $anonymized,
            'retention_months'  => $retentionMonths,
            'cutoff'            => $cutoff->toDateString(),
        ]);

        return self::SUCCESS;
    }
}
