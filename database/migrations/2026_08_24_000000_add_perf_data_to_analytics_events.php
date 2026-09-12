<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->json('perf_data')->nullable()->after('dwell_seconds');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('analytics_events')) {
            return;
        }
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->dropColumn('perf_data');
        });
    }
};
