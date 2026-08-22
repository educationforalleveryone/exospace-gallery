<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION-5 (AUDIT-P1-5.3): PII retention for the newsletter_signups table.
 *
 * The newsletter_signups table stores emails captured in the entrance curtain
 * of galleries (before visitors enter the 3D experience). Each entry has:
 *   - email: the visitor's email (PII — the primary data captured)
 *   - name: optional name (PII)
 *   - ip_address: the visitor's IP (PII — used for spam analysis)
 *   - referrer: where the visitor came from (may contain query-string PII)
 *   - gallery_id: FK to the gallery (preserved for analytics)
 *
 * Without this command, PII is retained indefinitely — a GDPR violation.
 * Curators need the email to send gallery updates, but after the retention
 * window the legitimate business interest expires.
 *
 * This command anonymizes PII on newsletter_signups rows older than the
 * retention window (default: 18 months):
 *   - email → 'anonymized:' + hash (preserves "same person signed up for
 *     multiple galleries" correlation for analytics)
 *   - name → null
 *   - ip_address → null
 *   - referrer → null
 *
 * The gallery_id + signed_up_at + timestamps are preserved for aggregate
 * analytics ("how many signups did this gallery get last year?").
 *
 * Schedule: monthly (1st of each month) via routes/console.php, running
 * after exospace:anonymize-rsvp-pii.
 *
 * Idempotent: re-running on already-anonymized rows is a no-op.
 *
 * NOTE: The unique constraint on (gallery_id, email) means the anonymized
 * email hash must be unique per gallery. Since the hash includes APP_KEY +
 * the original email, two different emails produce two different hashes —
 * so the unique constraint is preserved. If a collision somehow occurred
 * (astronomically unlikely with SHA-256 truncated to 16 chars), the UPDATE
 * would throw a duplicate-key error and the chunkById cursor would retry
 * the batch. This is acceptable — the operator would see the error in the
 * log and can manually investigate.
 */
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

        // Count rows that need anonymization.
        // Use signed_up_at (the authoritative signup date) for the cutoff,
        // matching how the table is queried in analytics.
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
