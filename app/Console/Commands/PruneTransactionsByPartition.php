<?php

declare(strict_types=1);

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
 *
 * ITERATION-003 FIX (audit C-1): The partition-pruning logic was BROKEN —
 * it used FROM_DAYS() to decode the PARTITION_DESCRIPTION, but the migration
 * partitions by UNIX_TIMESTAMP(created_at) (not TO_DAYS), so the description
 * is a Unix timestamp integer, not a day number. FROM_DAYS(1785494400)
 * returns a date in the year ~4.89 million, so the cutoff check always
 * passed and NO partitions were ever dropped. The 7-year retention policy
 * was silently broken — the transactions table grew unbounded.
 *
 * FIX: Use Carbon::createFromTimestamp() (or FROM_UNIXTIME() in SQL) instead
 * of FROM_DAYS(). Now old partitions are correctly dropped.
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

            // C-1 FIX: The migration partitions by UNIX_TIMESTAMP(created_at),
            // so the VALUES LESS THAN must be a Unix timestamp integer, not a
            // date string. (The old code used a date string here, which would
            // have caused partition-pruning issues — but since the migration
            // already created the initial 15 partitions correctly with
            // UNIX_TIMESTAMP, this only affects newly-created future partitions.)
            //
            // We use strtotime() to convert the date to a Unix timestamp.
            $lessThanTimestamp = strtotime($lessThan);
            $ddl = "ALTER TABLE transactions ADD PARTITION (PARTITION {$partitionName} VALUES LESS THAN ({$lessThanTimestamp}))";

            if ($dryRun) {
                $this->line("  [DRY-RUN] Would create: {$partitionName} (< {$lessThan} = ts {$lessThanTimestamp})");
            } else {
                try {
                    DB::statement($ddl);
                    $this->info("  Created partition: {$partitionName} (< {$lessThan} = ts {$lessThanTimestamp})");
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
     *
     * ITERATION-003 FIX (audit C-1): The old code used FROM_DAYS() to decode
     * the PARTITION_DESCRIPTION, but the migration partitions by
     * UNIX_TIMESTAMP(created_at), so the description is a Unix timestamp
     * integer. FROM_DAYS(1785494400) returns a date in the year ~4.89
     * million, so the cutoff check `if ($upperDate >= $cutoff) continue;`
     * ALWAYS passed (the date was always in the far future) and NO
     * partitions were ever dropped. The 7-year retention policy was silently
     * broken — the transactions table grew unbounded.
     *
     * FIX: Use Carbon::createFromTimestamp() (which expects a Unix timestamp)
     * instead of FROM_DAYS() (which expects a day number). Now old partitions
     * are correctly identified and dropped.
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

            // C-1 FIX: PARTITION_DESCRIPTION for RANGE partitions is the
            // LESS THAN value. Our migration used:
            //   PARTITION BY RANGE (UNIX_TIMESTAMP(created_at))
            //   PARTITION p202607 VALUES LESS THAN (UNIX_TIMESTAMP('2026-08-01'))
            //
            // So PARTITION_DESCRIPTION is a Unix timestamp INTEGER (e.g.
            // 1722470400 for 2024-08-01 00:00:00 UTC), NOT a day number
            // and NOT a date string.
            //
            // The old code used FROM_DAYS() which expects a day number —
            // FROM_DAYS(1722470400) returns a date in the year ~4.7 million,
            // so the cutoff check always passed and no partitions were dropped.
            //
            // The new code uses Carbon::createFromTimestamp() which correctly
            // interprets the integer as a Unix timestamp.
            $upperBound = null;
            if (ctype_digit((string) $description)) {
                $timestamp = (int) $description;
                // C-1 FIX: use Carbon::createFromTimestamp (Unix timestamp)
                // instead of DB::selectOne('SELECT FROM_DAYS(?) ...').
                // FROM_DAYS expects a day number; our partition description
                // is a Unix timestamp.
                try {
                    $upperDate = \Carbon\Carbon::createFromTimestamp($timestamp);
                    $upperBound = $upperDate->toDateString();
                } catch (\Throwable $e) {
                    // Invalid timestamp — skip this partition.
                    Log::warning('PruneTransactionsByPartition: could not parse partition description as Unix timestamp', [
                        'partition'        => $name,
                        'description'      => $description,
                        'error'            => $e->getMessage(),
                    ]);
                    continue;
                }
            } else {
                // The description is a string (e.g. a date or MAXVALUE).
                // This shouldn't happen for our UNIX_TIMESTAMP-based partitions,
                // but handle it defensively.
                $upperBound = $description;
            }

            if (! $upperBound) {
                continue;
            }

            // Defensive: only drop if the partition's upper bound is
            // strictly before the cutoff. This prevents dropping the
            // current month or future months.
            try {
                if (! isset($upperDate)) {
                    $upperDate = \Carbon\Carbon::parse($upperBound);
                }
            } catch (\Throwable $e) {
                continue;
            }

            if ($upperDate >= $cutoff) {
                continue;
            }

            if ($dryRun) {
                $this->line("  [DRY-RUN] Would drop: {$name} (upper bound {$upperBound}, age {$upperDate->diffForHumans()})");
            } else {
                try {
                    DB::statement("ALTER TABLE transactions DROP PARTITION {$name}");
                    $this->warn("  Dropped partition: {$name} (upper bound {$upperBound}, age {$upperDate->diffForHumans()})");

                    Log::info('PruneTransactionsByPartition: dropped old partition', [
                        'partition'   => $name,
                        'upper_bound' => $upperBound,
                        'age_days'    => $upperDate->diffInDays(now()),
                    ]);
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
