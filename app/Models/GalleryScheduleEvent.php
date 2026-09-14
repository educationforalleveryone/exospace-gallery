<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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

    /**
     * Resolve a declared IANA timezone name to a timezone object. Blank or
     * unknown identifiers fall back to UTC so a malformed stored value can
     * never break schedule rendering.
     */
    public static function resolveTimezone(?string $timezone): \DateTimeZone
    {
        if ($timezone !== null && $timezone !== '') {
            try {
                return new \DateTimeZone($timezone);
            } catch (\Throwable) {
                // Unknown identifier — resolve to UTC below.
            }
        }

        return new \DateTimeZone('UTC');
    }

    public function eventTimezone(): \DateTimeZone
    {
        return self::resolveTimezone($this->timezone);
    }

    /**
     * Interpret curator-entered wall-clock input ("2026-07-01T18:00") as local
     * time in the event's declared timezone and normalise it to the UTC
     * instant that storage and schedule queries operate on. Values carrying an
     * explicit offset keep that offset.
     */
    public static function fromEventLocalTime(string $value, ?string $timezone): Carbon
    {
        return Carbon::parse($value, self::resolveTimezone($timezone))->utc();
    }

    /**
     * Instants rendered in the event's declared timezone, so the public page,
     * admin screens and the RSVP notification all show the wall-clock the
     * curator scheduled regardless of the server's timezone.
     */
    public function startsAtInEventTimezone(): ?Carbon
    {
        return $this->starts_at ? (clone $this->starts_at)->setTimezone($this->eventTimezone()) : null;
    }

    public function endsAtInEventTimezone(): ?Carbon
    {
        return $this->ends_at ? (clone $this->ends_at)->setTimezone($this->eventTimezone()) : null;
    }

    /**
     * Human-readable start–end line. An end on a different calendar day
     * repeats the day so a range like 11:00 PM – 1:00 AM stays unambiguous.
     */
    public function scheduleLabel(): string
    {
        $start = $this->startsAtInEventTimezone();

        if ($start === null) {
            return '';
        }

        $end = $this->endsAtInEventTimezone();
        $startLabel = $start->format('l, F j, Y \a\t g:i A T');

        if ($end === null) {
            return $startLabel;
        }

        if ($end->isSameDay($start)) {
            return $startLabel . ' – ' . $end->format('g:i A T');
        }

        return $startLabel . ' – ' . $end->format('l, F j, Y \a\t g:i A T');
    }

    /**
     * The joinable external location link. Restricted to http(s) so a stored
     * value can never produce a javascript:/data: link on a public page.
     */
    public function externalLocationUrl(): ?string
    {
        $url = trim((string) $this->location_url);

        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return $url;
        }

        return null;
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
