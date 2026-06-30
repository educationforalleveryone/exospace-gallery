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
 */
class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $table = 'analytics_events';

    protected $fillable = [
        'gallery_id', 'image_id', 'event',
        'session_token', 'dwell_seconds', 'referrer', 'country',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
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
