<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\OnboardingSnapshot
 *
 * ITERATION 5 — persisted point-in-time capture of the onboarding funnel +
 * TTFE/TTFG, written weekly by exospace:onboarding-analytics (all three
 * dashboard windows: 7/30/90 days) and read by the Master Control trend
 * chart.
 *
 * Why a table at all: the live cohort erodes over time (GDPR deletions,
 * monthly PII anonymization, the 90-day analytics rollup pruning), so the
 * only faithful history of the product's headline metric — time to first
 * published exhibition — is a snapshot taken when the week closed.
 *
 * Aggregate data only: counts and timing statistics, zero user-level PII.
 * Rows are pruned after 2 years by exospace:cleanup-stale as hygiene, not
 * as a legal retention bound.
 *
 * Immutable-by-convention: rows are never updated except by the idempotent
 * updateOrCreate inside the same capture hour (schedule retry / manual
 * re-run), which is why $timestamps is off and captured_at is explicit.
 */
class OnboardingSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'onboarding_snapshots';

    protected $fillable = [
        'window_days',
        'registered',
        'created_gallery',
        'uploaded_image',
        'published',
        'got_views',
        'ttfg_min',
        'ttfg_avg',
        'ttfg_max',
        'ttfe_min',
        'ttfe_avg',
        'ttfe_max',
        'captured_at',
    ];

    protected $casts = [
        'window_days'     => 'integer',
        'registered'      => 'integer',
        'created_gallery' => 'integer',
        'uploaded_image'  => 'integer',
        'published'       => 'integer',
        'got_views'       => 'integer',
        'ttfg_min'        => 'float',
        'ttfg_avg'        => 'float',
        'ttfg_max'        => 'float',
        'ttfe_min'        => 'float',
        'ttfe_avg'        => 'float',
        'ttfe_max'        => 'float',
        'captured_at'     => 'datetime',
    ];

    /**
     * Chronological trend for one window — oldest first, so the Master
     * Control chart can feed the rows straight into Chart.js labels/data.
     */
    public function scopeTrend(Builder $q, int $windowDays, int $limit = 26): Builder
    {
        return $q->where('window_days', $windowDays)
            ->orderBy('captured_at')
            ->limit(max(1, min(156, $limit)));
    }
}
