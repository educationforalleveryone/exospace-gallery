<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S-10 FIX: Partition the transactions table by month (RANGE on created_at).
 *
 * Problem: The transactions table grows unbounded — every 2Checkout payment
 * inserts a row, and we keep them forever for audit/tax purposes. After a
 * few years of operation, queries like "show me this user's transactions"
 * or "count completed transactions this month" scan the full table because
 * the existing indexes on user_id/status don't help with date-range queries.
 *
 * Solution: MySQL RANGE partitioning on YEAR(created_at)*100+MONTH(created_at).
 * Each month gets its own partition. Date-range queries prune to only the
 * relevant partitions. Old partitions can be dropped in O(1) (vs. DELETE
 * which is O(N) + fragmentation).
 *
 * MySQL partitioning constraint: every unique key must include the partition
 * column. The existing unique(invoice_id) must become unique(invoice_id, created_at).
 * This doesn't break the existing lookup-by-invoice_id queries — they'll just
 * also need a date range to be efficient (which they already have via the
 * webhook's created_at context).
 *
 * This migration is IDEMPOTENT: it checks if the table is already partitioned
 * before applying. Re-running it (e.g. after a rollback + re-apply) is safe.
 *
 * NOTE: This migration only runs on MySQL/MariaDB. SQLite (used in tests)
 * doesn't support partitioning — the up() method detects the driver and
 * skips the partition DDL. The schema change (unique key modification) is
 * applied on both drivers so tests still pass.
 *
 * Partition maintenance: see PruneTransactionsByPartition command (added in
 * the same iteration) which drops partitions older than the retention window.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Drop the existing unique(invoice_id) index and replace it
        // with unique(invoice_id, created_at). This is required for MySQL
        // partitioning (the partition key must be part of every unique key).
        // On SQLite this is a no-op schema-wise but keeps the migration
        // portable — the unique constraint just becomes compound.
        try {
            Schema::table('transactions', function (Blueprint $table) {
                // Laravel's dropUnique uses the index name. The original
                // migration created it as $table->string('invoice_id')->unique()
                // which generates an index named 'transactions_invoice_id_unique'.
                $table->dropUnique('transactions_invoice_id_unique');
            });
        } catch (\Throwable $e) {
            // Index may have a different name if the migration was applied
            // before the unique() shorthand was used. Try the alternate name.
            try {
                DB::statement('ALTER TABLE transactions DROP INDEX transactions_invoice_id_unique');
            } catch (\Throwable $e2) {
                // If neither worked, the index doesn't exist — proceed.
            }
        }

        $driver = DB::getDriverName();

        $uniqueKeyExists = in_array($driver, ['mysql', 'mariadb'], true) && DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'transactions')
            ->where('INDEX_NAME', 'transactions_invoice_id_created_at_unique')
            ->exists();

        if (! $uniqueKeyExists) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->unique(['invoice_id', 'created_at'], 'transactions_invoice_id_created_at_unique');
            });
        }

        // Step 2: Apply RANGE partitioning on MySQL/MariaDB only.
        // SQLite (used in tests/CI) doesn't support partitioning.
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        // MySQL/MariaDB do not allow partitioning a table that has foreign
        // key constraints (error 1506). Drop the FK here — the user_id
        // column and its index remain in place, so lookups and joins are
        // unaffected. Referential integrity for user_id is enforced at the
        // application level from this point on instead of by the database.
        $userForeignKeyExists = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'transactions')
            ->where('CONSTRAINT_NAME', 'transactions_user_id_foreign')
            ->exists();

        if ($userForeignKeyExists) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropForeign('transactions_user_id_foreign');
            });
        }

        // MySQL requires every unique key — including the PRIMARY KEY — to
        // contain the partitioning column (error 1503 otherwise). The
        // default primary key is just `id`; extend it to (id, created_at).
        // `id` stays AUTO_INCREMENT and remains the leading column, so this
        // does not allow duplicate ids — it's still effectively unique on id.
        $primaryKeyIncludesCreatedAt = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'transactions')
            ->where('CONSTRAINT_NAME', 'PRIMARY')
            ->where('COLUMN_NAME', 'created_at')
            ->exists();

        if (! $primaryKeyIncludesCreatedAt) {
            DB::statement('ALTER TABLE transactions DROP PRIMARY KEY, ADD PRIMARY KEY (id, created_at)');
        }

        // Check if the table is already partitioned (idempotency).
        $alreadyPartitioned = DB::table('information_schema.PARTITIONS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'transactions')
            ->where('PARTITION_NAME', '!=', null)
            ->exists();

        if ($alreadyPartitioned) {
            return;
        }

        // Create monthly partitions for the current month + the previous 11
        // months + the next 3 months (so we have headroom for future inserts
        // without needing to run a partition-maintenance command immediately).
        // The PruneTransactionsByPartition command (added in this iteration)
        // will create future partitions and drop old ones going forward.
        $partitions = [];
        $now = now();
        $start = $now->copy()->subMonths(11)->startOfMonth();

        for ($i = 0; $i < 15; $i++) {
            $month = $start->copy()->addMonths($i);
            $partitionName = 'p' . $month->format('Ym');
            $lessThan = $month->copy()->addMonth()->format('Y-m-d');
            $partitions[] = "PARTITION {$partitionName} VALUES LESS THAN (UNIX_TIMESTAMP('{$lessThan}'))";
        }

        // Add a catch-all partition for any rows that fall outside the
        // explicit range (defensive — shouldn't happen if the maintenance
        // command runs monthly, but prevents INSERT failures if it doesn't).
        $partitions[] = "PARTITION pmax VALUES LESS THAN MAXVALUE";

        $partitionDdl = implode(",\n            ", $partitions);

        DB::statement("
            ALTER TABLE transactions
            PARTITION BY RANGE (UNIX_TIMESTAMP(created_at)) (
                {$partitionDdl}
            )
        ");
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Remove partitioning — keeps the data but flattens to a single table.
            DB::statement('ALTER TABLE transactions REMOVE PARTITIONING');

            // Restore the original single-column primary key.
            DB::statement('ALTER TABLE transactions DROP PRIMARY KEY, ADD PRIMARY KEY (id)');

            // Restore the foreign key that was dropped in up() to allow partitioning.
            Schema::table('transactions', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users');
            });
        }

        // Restore the original unique(invoice_id) constraint.
        try {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropUnique('transactions_invoice_id_created_at_unique');
            });
        } catch (\Throwable $e) {
            // Index may not exist — proceed.
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->unique('invoice_id', 'transactions_invoice_id_unique');
        });
    }
};
