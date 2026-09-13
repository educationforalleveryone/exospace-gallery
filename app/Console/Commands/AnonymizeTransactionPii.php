<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // Anonymize transactions.
        if ($only === '' || $only === 'transactions') {
            $totalAnonymized += $this->anonymizeTransactions($cutoff, $dryRun, $batchSize);
        }

        // Anonymize invoices.
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

    private function anonymizeInvoices($cutoff, bool $dryRun, int $batchSize): int
    {
        $this->newLine();
        $this->info("── Invoices ──");

        if (! \Illuminate\Support\Facades\Schema::hasTable('invoices')) {
            $this->warn("  Invoices table does not exist — skipping invoice anonymization.");
            return 0;
        }

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
