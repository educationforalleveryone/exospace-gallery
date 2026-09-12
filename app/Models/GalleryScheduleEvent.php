<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(EventRsvp::class, 'schedule_event_id');
    }

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

        if (array_key_exists('rsvps_count', $this->attributesToArray())) {
            return $this->rsvps_count >= $this->capacity;
        }

        return $this->rsvps()->count() >= $this->capacity;
    }

    public function spotsRemaining(): ?int
    {
        if (!$this->capacity) return null;

        if (array_key_exists('rsvps_count', $this->attributesToArray())) {
            return max(0, $this->capacity - $this->rsvps_count);
        }

        return max(0, $this->capacity - $this->rsvps()->count());
    }
}
