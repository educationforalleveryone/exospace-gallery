<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // CI FIX (Iteration 1 premium audit): `SHOW INDEX` is MySQL-only and
        // aborted migrate:fresh on SQLite (CI). Use the portable schema
        // helper — it checks index existence on every driver.
        $indexExists = collect(
            Schema::getIndexes('transactions')
        )->contains(fn ($index) => ($index['name'] ?? '') === 'transactions_invoice_id_unique');

        if (!$indexExists) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('invoice_id')->unique()->change();
            });
        }
    }

    public function down(): void
    {
        // ITERATION-1 FIX (consolidated-migration coexistence): rollback
        // runs additive migrations' down() in reverse batch order — the
        // target table may already be gone (owned by the consolidated
        // migration that runs later in the same batch on fresh installs).
        if (! Schema::hasTable('transactions')) {
            return;
        }
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['invoice_id']);
        });
    }
};