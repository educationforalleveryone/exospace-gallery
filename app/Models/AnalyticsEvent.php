<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\AnalyticsEvent
 *
 * Renamed from GalleryEvent in Round 4 to free up the `gallery_events`
 * table name for actual calendar events (opening receptions, artist talks).
 *
 * The table was renamed by migration 2026_06_22_000001. This model
 * explicitly declares the new table name (`analytics_events`) so any
 * existing code that references the model continues to work.
 *
 * Stores: view, focus, tour_start, tour_complete, dwell events.
 * Used by the AnalyticsController for the curator's analytics dashboard.
 *
 * ITERATION-003 FIX (audit C-3): Removed 'country' from $fillable.
 *
 * The 'country' column was dropped by migration
 * 2026_07_04_000004_drop_country_from_analytics_events.php (it was never
 * populated — geo-IP lookup was never implemented). The model still listed
 * 'country' in $fillable, which meant any future code that did
 * AnalyticsEvent::create(['country' => $geoipCountry, ...]) or
 * fill($request->validated()) would trigger a MySQL INSERT that fails
 * with "Unknown column 'country'".
 *
 * The fix removes 'country' from $fillable. If geo-IP tracking is ever
 * re-added, the column must be re-added in a migration AND the model
 * updated in the same PR.
 */
class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $table = 'analytics_events';

    protected $fillable = [
        'gallery_id', 'image_id', 'event',
        'session_token', 'dwell_seconds', 'perf_data', 'referrer',
        'created_at',
        // C-3 FIX (Iter-003): 'country' removed — column was dropped by
        // 2026_07_04_000004_drop_country_from_analytics_events.php.
        // Re-add only if the column is re-added in a future migration.
    ];

    protected $casts = [
        'created_at' => 'datetime',
        // PERF-F31: perf telemetry beacon payload (JSON column, nullable)
        'perf_data'  => 'array',
    ];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(GalleryImage::class, 'image_id');
    }
}
