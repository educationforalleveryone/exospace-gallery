<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $table = 'analytics_events';

    protected $fillable = [
        'gallery_id', 'image_id', 'event',
        'session_token', 'dwell_seconds', 'perf_data', 'referrer',
        'created_at',
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
