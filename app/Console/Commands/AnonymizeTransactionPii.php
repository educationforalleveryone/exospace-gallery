<?php

declare(strict_types=1);

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
 *
 * ITERATION-003 FIX (audit G-5): Now also anonymizes the invoices table.
 *
 * The invoices table holds the SAME PII (customer_email, customer_name,
 * billing_address) copied from the transaction at issue time. The
 * InvoiceGenerator's comment claimed invoices "retain the financial record"
 * — but it preserved the PII, not just the financial record, contradicting
 * GDPR Art. 5(1)(e). Nothing anonymized the invoice PII.
 *
 * FIX: This command now anonymizes both tables in the same run:
 *   - transactions: customer_email → 'anonymized:' + hash, customer_name → null
 *   - invoices: customer_email → 'anonymized:' + hash, customer_name → null,
 *     billing_address → null
 *
 * The financial fields (amount, tax_amount, tax_rate, currency, invoice_number)
 * are preserved for tax audit compliance.
 */
class AnonymizeTransactionPii extends Command
{
    protected $signature = 'exospace:anonymize-pii
                            {--retention-months=18 : Anonymize PII on transactions/invoices older than this many months}
                            {--dry-run : Show what would be anonymized without executing}
                            {--batch-size=500 : Rows per batch (avoids locking the table)}
                            {--only= : Anonymize only "transactions" or "invoices" (default: both)}';

    protected $description = 'Anonymize customer_email + customer_name (+ billing_address on invoices) on old transactions and invoices (GDPR PII retention).';

    public function handle(): int
    {
        $retentionMonths = (int) $this->option('retention-months');
        $dryRun          = (bool) $this->option('dry-run');
        $batchSize       = (int) $this->option('batch-size');
        $only            = (string) $this->option('only');

        $cutoff = now()->subMonths($retentionMonths);

        $this->info("PII anonymization for records older than {$retentionMonths} months (before {$cutoff->toDateString()})");
        $this->info("  Dry run: " . ($dryRun ? 'YES' : 'NO'));
        $this->info("  Batch size: {$batchSize}");
        $this->info("  Scope: " . ($only ?: 'both transactions and invoices'));
        $this->newLine();

        $totalAnonymized = 0;

        // G-5 FIX: Anonymize transactions (original behavior).
        if ($only === '' || $only === 'transactions') {
            $totalAnonymized += $this->anonymizeTransactions($cutoff, $dryRun, $batchSize);
        }

        // G-5 FIX: Anonymize invoices (new — closes the GDPR gap).
        if ($only === '' || $only === 'invoices') {
            $totalAnonymized += $this->anonymizeInvoices($cutoff, $dryRun, $batchSize);
        }

        $this->newLine();
        $this->info("Anonymized {$totalAnonymized} total records.");

        Log::info('AnonymizeTransactionPii: complete', [
            'total_anonymized'  => $totalAnonymized,
            'retention_months'  => $retentionMonths,
            'cutoff'            => $cutoff->toDateString(),
            'scope'             => $only ?: 'both',
        ]);

        return self::SUCCESS;
    }

    /**
     * Anonymize PII on old transactions.
     */
    private function anonymizeTransactions($cutoff, bool $dryRun, int $batchSize): int
    {
        $this->info("── Transactions ──");

        // Count rows that need anonymization.
        $needsAnonymization = DB::table('transactions')
            ->where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('customer_email', 'not like', 'anonymized:%')
                  ->orWhereNotNull('customer_name');
            })
            ->count();

        if ($needsAnonymization === 0) {
            $this->info("  No transactions need anonymization (all old rows already anonymized).");
            return 0;
        }

        $this->info("  Found {$needsAnonymization} transactions needing anonymization.");

        if ($dryRun) {
            $this->warn("  [DRY-RUN] Would anonymize {$needsAnonymization} transactions. No changes made.");
            return 0;
        }

        $anonymized = 0;
        $appId = config('app.key');

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
                            'customer_email' => 'anonymized:' . substr(hash('sha256', $appId . $row->customer_email), 0, 16),
                            'customer_name'  => null,
                            'updated_at'     => now(),
                        ]);
                    $anonymized++;
                }

                $this->info("  Anonymized batch (running total: {$anonymized})");
            });

        $this->info("  Anonymized {$anonymized} transactions.");
        return $anonymized;
    }

    /**
     * ITERATION-003 FIX (G-5): Anonymize PII on old invoices.
     *
     * The invoices table holds the SAME PII as transactions (customer_email,
     * customer_name, plus billing_address). Without this, the invoices table
     * retains PII indefinitely — a GDPR violation.
     *
     * The financial fields (amount, tax_amount, tax_rate, currency,
     * invoice_number, pdf_path) are preserved for tax audit compliance.
     */
    private function anonymizeInvoices($cutoff, bool $dryRun, int $batchSize): int
    {
        $this->newLine();
        $this->info("── Invoices (G-5 fix) ──");

        // Only process if the invoices table exists (defensive — the table
        // is created by 2026_07_04_000012, which may not have run on very
        // old installs).
        if (! \Illuminate\Support\Facades\Schema::hasTable('invoices')) {
            $this->warn("  Invoices table does not exist — skipping invoice anonymization.");
            return 0;
        }

        // Count rows that need anonymization.
        // Invoices have an `issued_at` column (not `created_at`), which is
        // the authoritative issue date. We use issued_at for the cutoff.
        $needsAnonymization = DB::table('invoices')
            ->where('issued_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('customer_email', 'not like', 'anonymized:%')
                  ->orWhereNotNull('customer_name')
                  ->orWhereNotNull('billing_address');
            })
            ->count();

        if ($needsAnonymization === 0) {
            $this->info("  No invoices need anonymization (all old rows already anonymized).");
            return 0;
        }

        $this->info("  Found {$needsAnonymization} invoices needing anonymization.");

        if ($dryRun) {
            $this->warn("  [DRY-RUN] Would anonymize {$needsAnonymization} invoices. No changes made.");
            return 0;
        }

        $anonymized = 0;
        $appId = config('app.key');

        DB::table('invoices')
            ->where('issued_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('customer_email', 'not like', 'anonymized:%')
                  ->orWhereNotNull('customer_name')
                  ->orWhereNotNull('billing_address');
            })
            ->orderBy('id')
            ->chunkById($batchSize, function ($rows) use ($appId, &$anonymized) {
                foreach ($rows as $row) {
                    DB::table('invoices')
                        ->where('id', $row->id)
                        ->update([
                            'customer_email'  => 'anonymized:' . substr(hash('sha256', $appId . $row->customer_email), 0, 16),
                            'customer_name'   => null,
                            'billing_address' => null,
                            'updated_at'      => now(),
                        ]);
                    $anonymized++;
                }

                $this->info("  Anonymized batch (running total: {$anonymized})");
            });

        $this->info("  Anonymized {$anonymized} invoices.");
        return $anonymized;
    }
}
