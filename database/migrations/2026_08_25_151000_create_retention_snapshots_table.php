<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION 6 — retention cohort snapshots (retention history).
 *
 * Problem: exospace:cohort-retention printed its weekly matrix to scheduler
 * stdout plus one log line — the same blindness TTFE had before
 * Iteration 5. Worse, a retention matrix is inherently a moving target:
 * a cell (cohort × week) is only final once the week closes, and the live
 * cohort erodes over time (GDPR deletions, monthly PII anonymization).
 * Without point-in-time persistence there is NO faithful retention
 * history to chart or compare releases against.
 *
 * Design:
 *   - One row per (cohort_week_start, week_index, captured_at) — the
 *     weekly command writes only COMPLETE cells (capture time at or past
 *     the cell's week end), so a persisted value is final for the live
 *     data at capture; re-runs within the capture hour updateOrCreate
 *     the same rows (idempotent, same convention as onboarding_snapshots).
 *   - retained_pct is stored once (decimal 5,1) alongside cohort_size and
 *     active_count so historical percentages survive later cohort erosion
 *     (deletions change denominators if recomputed).
 *   - week_index 0 = registration week (W0), 1 = first follow-up week…
 *     capped at unsignedTinyInteger (≤255) — the command uses ≤ 25.
 *   - Aggregate data only: counts + a percentage, zero user-level PII.
 *     Pruned after 2 years by exospace:cleanup-stale (hygiene, same as
 *     onboarding_snapshots — not a legal bound).
 *
 * Reads: the Master Control trend chart selects, per capture, the most
 * recent complete cohort for week_index 1 and 2 (W1/W2 retention over
 * time) — served by the (week_index, captured_at) index.
 */
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
        // Guarded drop (portable across SQLite/MySQL, consistent with the
        // Iteration-1 rollback-safety convention).
        if (Schema::hasTable('retention_snapshots')) {
            Schema::drop('retention_snapshots');
        }
    }
};
