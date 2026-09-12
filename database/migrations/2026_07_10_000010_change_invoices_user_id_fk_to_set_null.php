<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->fixMysqlForeignKey();
        } else {
        }
    }

    public function down(): void
    {

        if (! Schema::hasTable('invoices')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $fks = $this->getForeignKeysOnColumn('invoices', 'user_id');

            foreach ($fks as $fk) {
                DB::statement("ALTER TABLE invoices DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}");
            }

            // Re-add with cascade (the original behavior).
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');
            });
        }
    }

    private function fixMysqlForeignKey(): void
    {
        $fks = $this->getForeignKeysOnColumn('invoices', 'user_id');

        if (empty($fks)) {
            DB::statement('ALTER TABLE invoices MODIFY user_id BIGINT UNSIGNED NULL');

            // No FK exists — add it with the correct onDelete behavior.
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('set null');
            });
            return;
        }

        foreach ($fks as $fk) {
            // Check the current DELETE_RULE.
            $currentRule = $fk->DELETE_RULE ?? 'NO ACTION';

            if (strtoupper($currentRule) === 'SET NULL') {
                // Already correct — no-op.
                continue;
            }

            // Drop the existing FK first.
            DB::statement("ALTER TABLE invoices DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}");

            DB::statement('ALTER TABLE invoices MODIFY user_id BIGINT UNSIGNED NULL');

            Schema::table('invoices', function (Blueprint $table) {
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('set null');
            });
        }
    }

    private function getForeignKeysOnColumn(string $table, string $column): array
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE as kcu')
            ->join('information_schema.REFERENTIAL_CONSTRAINTS as rc', function ($join) {
                $join->on('rc.CONSTRAINT_SCHEMA', '=', 'kcu.CONSTRAINT_SCHEMA')
                     ->on('rc.CONSTRAINT_NAME', '=', 'kcu.CONSTRAINT_NAME');
            })
            ->where('kcu.TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('kcu.TABLE_NAME', $table)
            ->where('kcu.COLUMN_NAME', $column)
            ->select('kcu.CONSTRAINT_NAME', 'rc.DELETE_RULE')
            ->get()
            ->all();
    }
};
