<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\RetentionSnapshot
 *
 * ITERATION 6 — persisted point-in-time retention matrix cells, written
 * weekly by exospace:cohort-retention and read by the Master Control
 * retention trend chart.
 *
 * One row = one (cohort week × week index) cell as measured at one
 * capture. Only COMPLETE cells are persisted (capture at or past the
 * cell's week end), so a stored value is final for the live data at
 * capture time; retained_pct is denormalized because the live cohort
 * erodes later (GDPR deletions, anonymization) and recomputing old cells
 * would silently rewrite history.
 *
 * Aggregate data only: cohort size, active count, a percentage. Zero
 * user-level PII. Pruned after 2 years by exospace:cleanup-stale
 * (hygiene, same convention as onboarding_snapshots).
 *
 * Immutable-by-convention: rows are only ever written by the idempotent
 * updateOrCreate in the same capture hour (schedule retry / manual
 * re-run), which is why $timestamps is off and captured_at is explicit.
 */
class RetentionSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'retention_snapshots';

    protected $fillable = [
        'cohort_week_start',
        'week_index',
        'cohort_size',
        'active_count',
        'retained_pct',
        'captured_at',
    ];

    protected $casts = [
        // 'date:Y-m-d' — the default 'date' cast serializes with H:i:s
        // ('2026-08-03 00:00:00'), which breaks equality lookups against
        // Y-m-d strings on SQLite (and stores noise in a DATE column).
        // Explicit Y-m-d keeps updateOrCreate keys + trend matching
        // identical across SQLite (tests) and MySQL (production).
        'cohort_week_start' => 'date:Y-m-d',
        'week_index'        => 'integer',
        'cohort_size'       => 'integer',
        'active_count'      => 'integer',
        'retained_pct'      => 'float',
        'captured_at'       => 'datetime',
    ];

    /**
     * Trend read model — per capture, the LATEST COMPLETE cohort measured
     * for the given week index (see CohortRetentionMetricsService::trend).
     */
    public function scopeTrend(Builder $q, int $weekIndex, int $limit = 26): Builder
    {
        return $q->where('week_index', max(0, min(255, $weekIndex)))
            ->orderByDesc('captured_at')
            ->limit(max(1, min(156, $limit)));
    }
}
