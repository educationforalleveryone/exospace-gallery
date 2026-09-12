<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            $upperBound = null;
            if (ctype_digit((string) $description)) {
                $timestamp = (int) $description;
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
                $upperBound = $description;
            }

            if (! $upperBound) {
                continue;
            }

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
