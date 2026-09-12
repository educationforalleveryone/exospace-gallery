<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('retention_snapshots')) {
            return; // rolling-deploy safety
        }

        Schema::create('retention_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('cohort_week_start');
            $table->unsignedTinyInteger('week_index');
            $table->unsignedInteger('cohort_size')->default(0);
            $table->unsignedInteger('active_count')->default(0);
            $table->decimal('retained_pct', 5, 1)->default(0);
            $table->timestamp('captured_at')->nullable();

            // Idempotent weekly writes: one row per (cohort, week, hour).
            $table->unique(['cohort_week_start', 'week_index', 'captured_at']);

            // Trend read path: WHERE week_index = ? ORDER BY captured_at.
            $table->index(['week_index', 'captured_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('retention_snapshots')) {
            Schema::drop('retention_snapshots');
        }
    }
};
