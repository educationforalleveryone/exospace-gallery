<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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
        if (! Schema::hasTable('transactions')) {
            return;
        }
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['invoice_id']);
        });
    }
};