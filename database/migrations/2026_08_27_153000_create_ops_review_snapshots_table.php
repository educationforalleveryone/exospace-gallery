<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpsCenter — Iteration 9 (Feature A): the weekly review's long memory.
 *
 * One row per weekly review DELIVERY INVOCATION (scheduled Monday send
 * or a manual "Send weekly review now") — NOT per composition: the
 * /ops/digest preview composes the review on every page load and must
 * stay side-effect-free. A week with both a scheduled send and a manual
 * re-send (or a dedup-suppressed scheduler re-fire, which send() cannot
 * observe — the dedup lives INSIDE alert()) may hold multiple rows;
 * every row's metrics are TRUE for its week, and the trend strip takes
 * the LATEST row per week_start, so duplicates are harmless by design.
 *
 * What it stores: the review's flow metrics (error totals, category
 * counts, incident throughput incl. MTTA/MTTR means, deployments,
 * sweep findings, operator activity) as a JSON blob keyed exactly like
 * OpsWeeklyReviewService computes them. This is aggregate COUNTS only —
 * no titles, no contexts, no payloads: nothing that could carry a secret
 * even if a section had miscomposed one.
 *
 * Why a table at all when the deltas are computed live: the Monday
 * message answers "this week vs last week"; the strip answers "what
 * kind of MONTH has it been" — and that question is only answerable if
 * each week's answer was written down when it was true. Prune with the
 * review's own retention (ops.weekly_review.snapshot_retention_days,
 * default 365) via the existing ops:prune-events command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_review_snapshots', function (Blueprint $table) {
            $table->id();
            // The Monday the window END rolled into (week_start = the
            // window's start date). Distinct rows may share a week_start
            // (manual re-sends); readers dedupe by latest id.
            $table->date('week_start');
            $table->date('week_end');
            $table->string('trigger', 20); // scheduled | manual
            // Aggregate counts only — see the class docblock.
            $table->json('metrics');
            $table->timestamp('created_at')->index();

            $table->index(['week_start', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_review_snapshots');
    }
};
