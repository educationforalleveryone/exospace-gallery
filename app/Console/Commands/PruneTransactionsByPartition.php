<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * S-10 FIX: Partition maintenance for the transactions table.
 *
 * MySQL RANGE partitioning requires explicit partition creation ahead of
 * time — if you try to INSERT a row with a created_at that doesn't fall
 * into any existing partition, MySQL throws "Table has no partition for
 * value X" and the INSERT fails. This command:
 *
 *   1. Creates partitions for the next 3 months (headroom for future inserts)
 *   2. Drops partitions older than the retention window (default: 7 years,
 *      per tax/audit record-keeping requirements — IRS requires 7 years for
 *      financial records)
 *
 * Schedule: monthly (first of each month) via routes/console.php.
 *
 * Why 7 years: tax authorities (IRS, HMRC, etc.) require financial records
 * to be retained for 7 years. Dropping partitions older than 7 years keeps
 * the table small (84 monthly partitions max) while staying compliant.
 *
 * The command is idempotent: creating an existing partition is a no-op
 * (we check INFORMATION_SCHEMA first), and dropping a non-existent
 * partition is a no-op (we check before dropping).
 */
class PruneTransactionsByPartition extends Command
{
    protected $signature = 'exospace:prune-transactions
                            {--retention-years=7 : Drop partitions older than this many years}
                            {--future-months=3 : Create partitions for this many future months}
                            {--dry-run : Show what would be done without executing}';

    protected $description = 'Create future + drop old monthly partitions on the transactions table.';

    public function handle(): int
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->info("Driver {$driver} doesn't support partitioning — skipping.");
            return self::SUCCESS;
        }

        $retentionYears = (int) $this->option('retention-years');
        $futureMonths   = (int) $this->option('future-months');
        $dryRun         = (bool) $this->option('dry-run');

        $this->info("Transactions partition maintenance");
        $this->info("  Retention: {$retentionYears} years");
        $this->info("  Future partitions: {$futureMonths} months");
        $this->info("  Dry run: " . ($dryRun ? 'YES' : 'NO'));
        $this->newLine();

        $created = $this->createFuturePartitions($futureMonths, $dryRun);
        $dropped = $this->dropOldPartitions($retentionYears, $dryRun);

        $this->newLine();
        $this->info("Summary: created {$created} partitions, dropped {$dropped} partitions.");

        Log::info('PruneTransactionsByPartition: complete', [
            'created'         => $created,
            'dropped'         => $dropped,
            'retention_years' => $retentionYears,
            'future_months'   => $futureMonths,
            'dry_run'         => $dryRun,
        ]);

        return self::SUCCESS;
    }

    /**
     * Create partitions for the next N months (including the current month).
     */
    private function createFuturePartitions(int $futureMonths, bool $dryRun): int
    {
        $created = 0;
        $now = now();

        for ($i = 0; $i <= $futureMonths; $i++) {
            $month = $now->copy()->addMonths($i)->startOfMonth();
            $partitionName = 'p' . $month->format('Ym');
            $lessThan = $month->copy()->addMonth()->format('Y-m-d');

            // Check if partition already exists.
            $exists = DB::table('information_schema.PARTITIONS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'transactions')
                ->where('PARTITION_NAME', $partitionName)
                ->exists();

            if ($exists) {
                continue;
            }

            $ddl = "ALTER TABLE transactions ADD PARTITION (PARTITION {$partitionName} VALUES LESS THAN ('{$lessThan}'))";

            if ($dryRun) {
                $this->line("  [DRY-RUN] Would create: {$partitionName} (< {$lessThan})");
            } else {
                try {
                    DB::statement($ddl);
                    $this->info("  Created partition: {$partitionName} (< {$lessThan})");
                } catch (\Throwable $e) {
                    $this->error("  Failed to create {$partitionName}: {$e->getMessage()}");
                    continue;
                }
            }

            $created++;
        }

        return $created;
    }

    /**
     * Drop partitions older than the retention window.
     *
     * IMPORTANT: This DROPs the partition AND its data. We log every drop
     * at info level for audit. Before dropping, we verify the partition's
     * upper bound is strictly before the retention cutoff (defensive —
     * prevents accidental drop of the current month's data).
     */
    private function dropOldPartitions(int $retentionYears, bool $dryRun): int
    {
        $cutoff = now()->subYears($retentionYears)->startOfMonth();
        $dropped = 0;

        // Get all partitions for the transactions table.
        $partitions = DB::table('information_schema.PARTITIONS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'transactions')
            ->whereNotNull('PARTITION_NAME')
            ->where('PARTITION_NAME', '!=', 'pmax')
            ->orderBy('PARTITION_ORDINAL_POSITION')
            ->get(['PARTITION_NAME', 'PARTITION_DESCRIPTION']);

        foreach ($partitions as $p) {
            $name = $p->PARTITION_NAME;
            $description = $p->PARTITION_DESCRIPTION;

            // PARTITION_DESCRIPTION for RANGE partitions is the LESS THAN
            // value (a date string in our case, since we used TO_DAYS(created_at)
            // — actually, TO_DAYS returns an integer, so the description is
            // an integer day number. We need to convert it back to a date.
            //
            // Wait — actually our migration used:
            //   PARTITION BY RANGE (TO_DAYS(created_at))
            //   PARTITION p202607 VALUES LESS THAN ('2026-08-01')
            //
            // The PARTITION_DESCRIPTION for a RANGE COLUMNS or RANGE on
            // TO_DAYS() is the literal value from the DDL — in our case
            // the date string '2026-08-01'. Let me handle both forms.
            $upperBound = null;
            if (ctype_digit((string) $description)) {
                // Integer day number (from TO_DAYS) — convert via FROM_DAYS
                $upperBound = DB::selectOne('SELECT FROM_DAYS(?) AS d', [(int) $description])?->d;
            } else {
                $upperBound = $description;
            }

            if (! $upperBound) {
                continue;
            }

            // Defensive: only drop if the partition's upper bound is
            // strictly before the cutoff. This prevents dropping the
            // current month or future months.
            try {
                $upperDate = \Carbon\Carbon::parse($upperBound);
            } catch (\Throwable $e) {
                continue;
            }

            if ($upperDate >= $cutoff) {
                continue;
            }

            if ($dryRun) {
                $this->line("  [DRY-RUN] Would drop: {$name} (upper bound {$upperBound})");
            } else {
                try {
                    DB::statement("ALTER TABLE transactions DROP PARTITION {$name}");
                    $this->warn("  Dropped partition: {$name} (upper bound {$upperBound})");
                } catch (\Throwable $e) {
                    $this->error("  Failed to drop {$name}: {$e->getMessage()}");
                    continue;
                }
            }

            $dropped++;
        }

        return $dropped;
    }
}
