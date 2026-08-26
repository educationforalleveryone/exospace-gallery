<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION 5 — onboarding metric snapshots (TTFE history).
 *
 * Problem: OnboardingMetricsService computes the funnel + TTFE/TTFG on the
 * fly from live tables, and the weekly exospace:onboarding-analytics command
 * prints it to scheduler stdout plus one log line. That is the ONLY record
 * of the product's headline metric over time — unqueryable, lost to log
 * rotation, and invisible to the Master Control dashboard between Monday
 * reports. The live cohort itself erodes (GDPR deletions, monthly PII
 * anonymization, the 90-day analytics rollup pruning), so point-in-time
 * snapshots are the only faithful history.
 *
 * Design:
 *   - One row per (window_days, captured_at) — the weekly command snapshots
 *     all three dashboard windows (7/30/90) so every trend on Master Control
 *     has data. updateOrCreate semantics on the pair make re-runs (schedule
 *     retry, manual invocation within the hour) idempotent instead of
 *     polluting the trend with duplicate points.
 *   - captured_at is truncated to the start of the hour by the writer, so
 *     the unique index expresses "one snapshot per window per hour".
 *   - Aggregate data only — no user-level PII, so no GDPR retention bound;
 *     rows are pruned after 2 years by exospace:cleanup-stale purely to
 *     keep the table honest (≈156 rows/window/year).
 *   - ttfe/ttfg min/avg/max are nullable decimals (1 dp) mirroring the
 *     service's rounded stats; null means "no events in the window".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('window_days');
            $table->unsignedInteger('registered')->default(0);
            $table->unsignedInteger('created_gallery')->default(0);
            $table->unsignedInteger('uploaded_image')->default(0);
            $table->unsignedInteger('published')->default(0);
            $table->unsignedInteger('got_views')->default(0);
            $table->decimal('ttfg_min', 8, 1)->nullable();
            $table->decimal('ttfg_avg', 8, 1)->nullable();
            $table->decimal('ttfg_max', 8, 1)->nullable();
            $table->decimal('ttfe_min', 8, 1)->nullable();
            $table->decimal('ttfe_avg', 8, 1)->nullable();
            $table->decimal('ttfe_max', 8, 1)->nullable();
            $table->timestamp('captured_at')->nullable();

            // The unique constraint doubles as the (window_days, captured_at)
            // lookup index — no separate index needed (MySQL would treat a
            // duplicate index as pure write overhead).
            $table->unique(['window_days', 'captured_at']);
        });
    }

    public function down(): void
    {
        // Guarded drop (portable across SQLite/MySQL, consistent with the
        // Iteration-1 rollback-safety convention for shared test databases).
        if (Schema::hasTable('onboarding_snapshots')) {
            Schema::drop('onboarding_snapshots');
        }
    }
};
