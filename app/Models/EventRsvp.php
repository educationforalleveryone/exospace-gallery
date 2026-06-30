<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\EventRsvp
 *
 * A visitor's RSVP to a gallery calendar event. Captures name + email
 * (marketing asset for the curator). Unique on (schedule_event_id, email).
 */
class EventRsvp extends Model
{
    protected $fillable = [
        'schedule_event_id', 'name', 'email', 'ip_address', 'confirmed_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(GalleryScheduleEvent::class, 'schedule_event_id');
    }
}
