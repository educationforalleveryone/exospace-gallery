<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropUnique('transactions_invoice_id_unique');
            });
        } catch (\Throwable $e) {
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

        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

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

        $primaryKeyIncludesCreatedAt = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'transactions')
            ->where('CONSTRAINT_NAME', 'PRIMARY')
            ->where('COLUMN_NAME', 'created_at')
            ->exists();

        if (! $primaryKeyIncludesCreatedAt) {
            $inboundForeignKeys = DB::table('information_schema.KEY_COLUMN_USAGE as kcu')
                ->join('information_schema.REFERENTIAL_CONSTRAINTS as rc', function ($join) {
                    $join->on('rc.CONSTRAINT_SCHEMA', '=', 'kcu.CONSTRAINT_SCHEMA')
                         ->on('rc.CONSTRAINT_NAME', '=', 'kcu.CONSTRAINT_NAME');
                })
                ->where('kcu.CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
                ->where('kcu.REFERENCED_TABLE_NAME', 'transactions')
                ->where('kcu.REFERENCED_COLUMN_NAME', 'id')
                ->select('kcu.TABLE_NAME', 'kcu.COLUMN_NAME', 'kcu.CONSTRAINT_NAME')
                ->get();

            foreach ($inboundForeignKeys as $fk) {
                DB::statement("ALTER TABLE {$fk->TABLE_NAME} DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}");

                if (! Schema::hasIndex($fk->TABLE_NAME, "{$fk->TABLE_NAME}_{$fk->COLUMN_NAME}_index")) {
                    Schema::table($fk->TABLE_NAME, function (Blueprint $table) use ($fk) {
                        $table->index($fk->COLUMN_NAME);
                    });
                }
            }

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

        $partitions = [];
        $now = now();
        $start = $now->copy()->subMonths(11)->startOfMonth();

        for ($i = 0; $i < 15; $i++) {
            $month = $start->copy()->addMonths($i);
            $partitionName = 'p' . $month->format('Ym');
            $lessThan = $month->copy()->addMonth()->format('Y-m-d');
            $partitions[] = "PARTITION {$partitionName} VALUES LESS THAN (UNIX_TIMESTAMP('{$lessThan}'))";
        }

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
