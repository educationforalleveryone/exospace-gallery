<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('event_rsvps', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        $hasNulls = DB::table('transactions')->whereNull('user_id')->exists();
        if ($hasNulls) {
            throw new \RuntimeException('Cannot reverse: transactions with NULL user_id exist.');
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
