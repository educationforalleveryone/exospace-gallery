<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\VenueTemplateSnapshot
 *
 * Iteration 5 "Authoring" (roadmap P2.1, §9.2 #3): one pre-save state of a
 * venue template. Written by VenueSnapshotManager::capture() before every
 * admin save and before every restore; pruned to the newest 5 per venue.
 *
 * DELIBERATELY NOT a git replacement: no branching, no diffs-of-drafts, no
 * scheduled versions. One flat list per venue, newest first, one-click
 * restore. The payload holds only CONTENT an admin can change through the
 * form — identity (slug) and publication state (is_draft / published_at)
 * are NOT snapshotted, so restoring never renames a venue and never
 * silently (un)publishes it.
 */
class VenueTemplateSnapshot extends Model
{
    /**
     * Snapshots are immutable once written and the table has no updated_at
     * column — tell Eloquent to touch created_at only.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'venue_template_id',
        'label',
        'config',
        'created_by',
    ];

    protected $casts = [
        'config' => 'array',
    ];

    public function venueTemplate(): BelongsTo
    {
        return $this->belongsTo(VenueTemplate::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Newest five for a venue (the retention window).
     */
    public function scopeForVenue($query, int $venueId)
    {
        return $query->where('venue_template_id', $venueId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
