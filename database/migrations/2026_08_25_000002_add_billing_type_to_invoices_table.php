<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('billing_type', 20)->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('billing_type');
        });
    }
};
