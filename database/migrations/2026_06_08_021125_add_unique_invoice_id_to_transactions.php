<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $indexExists = collect(DB::select("SHOW INDEX FROM transactions WHERE Key_name = 'transactions_invoice_id_unique'"))->isNotEmpty();

        if (!$indexExists) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('invoice_id')->unique()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['invoice_id']);
        });
    }
};