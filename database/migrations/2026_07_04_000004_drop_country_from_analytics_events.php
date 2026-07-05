<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-9 FIX (audit): Drop the dead `country` column from analytics_events.
 *
 * The column was created in the original migration but never populated —
 * AnalyticsController::track() never sets it. It's always NULL. Dropping
 * it saves ~2 bytes per row and removes confusion for anyone reading the
 * schema.
 *
 * Reversible: down() re-adds the column (nullable, no data to restore).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            if (Schema::hasColumn('analytics_events', 'country')) {
                $table->dropColumn('country');
            }
        });
    }

    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            if (! Schema::hasColumn('analytics_events', 'country')) {
                $table->string('country', 2)->nullable()->after('referrer');
            }
        });
    }
};
