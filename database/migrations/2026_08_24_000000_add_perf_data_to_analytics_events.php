<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERF-F31 (3D audit — iteration 6): real-user perf telemetry.
 *
 * Adds a nullable JSON column `perf_data` to analytics_events for the 'perf'
 * beacon sent by the 3D viewer (one per engaged visit, after 15 s of FPS
 * sampling): { tier, q, fps, fps_min, draws, tris, pr, adapt, n, heap, net,
 * ms, partial }.
 *
 * Why a column instead of a new table: the beacon flows through the existing
 * POST /gallery/{gallery}/track pipeline (session hashing, cookie-consent
 * gate, throttling, 90-day prune) — a JSON column keeps all of that intact
 * with zero new code paths for storage. The rollup command uses explicit
 * CASE WHEN event='view' aggregates, so 'perf' rows are ignored there and
 * simply age out with the existing retention prune.
 *
 * Nullable + additive: old rows have NULL, the model casts to array, and the
 * track controller validates the payload shape before storing.
 */
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
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->dropColumn('perf_data');
        });
    }
};
