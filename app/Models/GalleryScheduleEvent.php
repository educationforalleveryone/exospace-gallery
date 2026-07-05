<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\GalleryScheduleEvent
 *
 * An actual calendar event tied to a gallery — opening reception,
 * artist talk, walkthrough, workshop, closing, etc.
 *
 * NOT to be confused with AnalyticsEvent (which stores analytics events
 * like views and dwell time).
 *
 * Visitors can RSVP via the EventRsvp model — email capture doubles as
 * a marketing asset for the curator.
 */
class GalleryScheduleEvent extends Model
{
    protected $table = 'gallery_schedule_events';

    protected $fillable = [
        'gallery_id', 'title', 'description', 'type',
        'starts_at', 'ends_at', 'timezone',
        'location_name', 'location_url',
        'capacity', 'is_active',
    ];

    protected $casts = [
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'is_active'  => 'boolean',
        'capacity'   => 'integer',
    ];

    public const TYPES = [
        'opening'     => 'Opening reception',
        'artist_talk' => 'Artist talk',
        'walkthrough' => 'Walkthrough',
        'workshop'    => 'Workshop',
        'closing'     => 'Closing event',
        'event'       => 'General event',
    ];

    // ─── Relationships ──────────────────────────────────────────────────

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(EventRsvp::class, 'schedule_event_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────

    public function scopeUpcoming(Builder $q): Builder
    {
        return $q->where('starts_at', '>=', now())->orderBy('starts_at');
    }

    public function scopePast(Builder $q): Builder
    {
        return $q->where('starts_at', '<', now())->orderByDesc('starts_at');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function isUpcoming(): bool
    {
        return $this->starts_at && $this->starts_at->isFuture();
    }

    public function isPast(): bool
    {
        return $this->starts_at && $this->starts_at->isPast();
    }

    public function isAtCapacity(): bool
    {
        if (!$this->capacity) return false;

        // PERF-15 FIX: Use withCount('rsvps') cache when available
        // instead of issuing a separate COUNT query on every call.
        // The gallery events page renders up to 20 events per gallery
        // — calling isAtCapacity() on each would issue 20 separate
        // COUNT queries. When the events are eager-loaded via
        // ->withCount('rsvps'), the count is available as
        // $event->rsvps_count (set by Laravel withCount magic) and
        // we use it directly.
        //
        // Callers that load events without withCount fall back to a
        // per-call COUNT query — still correct, just slower.
        if (array_key_exists('rsvps_count', $this->attributesToArray())) {
            return $this->rsvps_count >= $this->capacity;
        }

        return $this->rsvps()->count() >= $this->capacity;
    }

    public function spotsRemaining(): ?int
    {
        if (!$this->capacity) return null;

        // PERF-15: Same pattern as isAtCapacity() — prefer the
        // eager-loaded rsvps_count when available.
        if (array_key_exists('rsvps_count', $this->attributesToArray())) {
            return max(0, $this->capacity - $this->rsvps_count);
        }

        return max(0, $this->capacity - $this->rsvps()->count());
    }
}
