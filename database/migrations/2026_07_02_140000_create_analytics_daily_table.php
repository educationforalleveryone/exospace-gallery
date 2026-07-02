<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the analytics_daily rollup table.
     *
     * (Task H30 / audit H31) — the analytics_events table grows unboundedly.
     * Every view, focus, tour_start, and dwell creates a row. After 1 year
     * of moderate traffic (100 galleries × 1k views/month), the table has
     * 1.2M rows. The AnalyticsController runs 7+ aggregations per page
     * view against unbounded date ranges — this degrades fast.
     *
     * This rollup table stores per-gallery per-day counts, computed by a
     * scheduled artisan command (exospace:rollup-analytics). The
     * AnalyticsController can then query the rollup table for historical
     * data (fast) and the raw events table for today's data (fresh).
     *
     * Raw events older than 90 days are pruned by the same command.
     */
    public function up(): void
    {
        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('focuses')->default(0);
            $table->unsignedInteger('tour_starts')->default(0);
            $table->decimal('avg_dwell_seconds', 8, 2)->default(0);
            $table->timestamps();

            // One row per gallery per day
            $table->unique(['gallery_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily');
    }
};
