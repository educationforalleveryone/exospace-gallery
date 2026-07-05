<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEC-10 FIX: PII retention policy for the transactions table.
 *
 * The transactions table stores customer_email + customer_name (PII) for
 * every 2Checkout payment. Tax/audit regulations (IRS) require retaining
 * financial records for 7 years — but GDPR Article 5(1)(e) requires that
 * PII is "kept in a form which permits identification of data subjects for
 * no longer than is necessary".
 *
 * This command reconciles the two: it ANONYMIZES the PII fields
 * (customer_email, customer_name) on transactions older than a configurable
 * retention window (default: 18 months), while keeping the financial record
 * (amount, currency, plan, status, invoice_id, sale_id) intact for tax audit.
 *
 * The anonymization replaces PII with a one-way hash derived from the
 * original value + APP_KEY. This:
 *   - Removes the PII from the database (GDPR-compliant)
 *   - Preserves the ability to correlate transactions by the same (now-
 *     anonymized) customer email (e.g. for fraud detection)
 *   - Cannot be reversed without APP_KEY (and even then, only the hash is
 *     stored — the original email is gone)
 *
 * Schedule: monthly (1st of each month) via routes/console.php, after the
 * partition-prune command. Runs AFTER partition pruning so that old
 * partitions (which are dropped entirely) don't waste anonymization effort.
 *
 * The command is idempotent: re-running it on already-anonymized rows is
 * a no-op (the hash-of-hash is stable).
 */
class AnonymizeTransactionPii extends Command
{
    protected $signature = 'exospace:anonymize-pii
                            {--retention-months=18 : Anonymize PII on transactions older than this many months}
                            {--dry-run : Show what would be anonymized without executing}
                            {--batch-size=500 : Rows per batch (avoids locking the table)}';

    protected $description = 'Anonymize customer_email + customer_name on old transactions (GDPR PII retention).';

    public function handle(): int
    {
        $retentionMonths = (int) $this->option('retention-months');
        $dryRun          = (bool) $this->option('dry-run');
        $batchSize       = (int) $this->option('batch-size');

        $cutoff = now()->subMonths($retentionMonths);

        $this->info("PII anonymization for transactions older than {$retentionMonths} months (before {$cutoff->toDateString()})");
        $this->info("  Dry run: " . ($dryRun ? 'YES' : 'NO'));
        $this->info("  Batch size: {$batchSize}");
        $this->newLine();

        // Count rows that need anonymization.
        // We detect already-anonymized rows by checking if customer_email
        // starts with 'anonymized:' (the prefix we add when anonymizing).
        $needsAnonymization = DB::table('transactions')
            ->where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('customer_email', 'not like', 'anonymized:%')
                  ->orWhereNotNull('customer_name');
            })
            ->count();

        if ($needsAnonymization === 0) {
            $this->info("No transactions need anonymization (all old rows already anonymized).");
            return self::SUCCESS;
        }

        $this->info("Found {$needsAnonymization} transactions needing anonymization.");

        if ($dryRun) {
            $this->warn("[DRY-RUN] Would anonymize {$needsAnonymization} rows. No changes made.");
            return self::SUCCESS;
        }

        $anonymized = 0;
        $appId = config('app.key');

        // Process in batches to avoid locking the table.
        // Use chunkById for stable pagination (offset-based pagination
        // would skip rows if the data changes mid-loop).
        DB::table('transactions')
            ->where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('customer_email', 'not like', 'anonymized:%')
                  ->orWhereNotNull('customer_name');
            })
            ->orderBy('id')
            ->chunkById($batchSize, function ($rows) use ($appId, &$anonymized) {
                foreach ($rows as $row) {
                    DB::table('transactions')
                        ->where('id', $row->id)
                        ->update([
                            // Replace email with a stable hash prefix. The hash
                            // lets us correlate transactions from the same (now-
                            // anonymized) customer without revealing the email.
                            // We use sha256 truncated to 16 hex chars (64 bits)
                            // — collision-resistant for typical customer counts
                            // (< 10M customers → ~0.01% collision probability).
                            'customer_email' => 'anonymized:' . substr(hash('sha256', $appId . $row->customer_email), 0, 16),
                            // customer_name has no analytical value — just null it.
                            'customer_name'  => null,
                            'updated_at'     => now(),
                        ]);
                    $anonymized++;
                }

                $this->info("  Anonymized batch (running total: {$anonymized})");
            });

        $this->newLine();
        $this->info("Anonymized {$anonymized} transactions.");

        Log::info('AnonymizeTransactionPii: complete', [
            'anonymized_count'  => $anonymized,
            'retention_months'  => $retentionMonths,
            'cutoff'            => $cutoff->toDateString(),
        ]);

        return self::SUCCESS;
    }
}
