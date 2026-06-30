<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Gallery extends Model
{
    protected $fillable = [
        'user_id', 'team_id', 'title', 'slug', 'description',
        'wall_texture', 'frame_style', 'lighting_preset',
        'floor_material', 'audio_path', 'custom_logo_path',
        'room_layout', 'venue_template_id', 'pin_hash',
        'is_active', 'view_count',
        'opens_at', 'closes_at',
        'custom_domain',  // Studio-plan white-label CNAME support
        'is_featured',    // NEW (Round 4) — super-admin curated for /discover
        'curtain_logo_path',  // NEW (Round 4) — Studio-only custom entrance curtain logo
        'curtain_bg_color',   // NEW (Round 4) — Studio-only custom entrance curtain bg color
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'view_count' => 'integer',
        'opens_at'   => 'datetime',
        'closes_at'  => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($gallery) {
            if (empty($gallery->slug)) {
                $gallery->slug = Str::slug($gallery->title) . '-' . uniqid();
            }
        });

        // Normalise custom_domain on save: lowercase, strip scheme/path.
        static::saving(function ($gallery) {
            if (!empty($gallery->custom_domain)) {
                $domain = strtolower(trim($gallery->custom_domain));
                // Strip http:// or https:// prefix
                $domain = preg_replace('#^https?://#', '', $domain);
                // Strip any path component
                $domain = explode('/', $domain)[0];
                // Strip :port
                $domain = explode(':', $domain)[0];
                // Strip leading www. (we treat www and non-www as the same)
                // Actually keep www. — let DNS decide. Just trim whitespace.
                $gallery->custom_domain = $domain;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function venueTemplate(): BelongsTo
    {
        return $this->belongsTo(VenueTemplate::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(GalleryImage::class)->orderBy('position_order');
    }

    /** First image only — efficient thumbnail without loading full collection */
    public function coverImage(): HasOne
    {
        return $this->hasOne(GalleryImage::class)->orderBy('position_order');
    }

    /** Analytics events (view, focus, tour_start, dwell) — renamed from GalleryEvent in Round 4 */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    /** Schedule events (calendar) — opening receptions, artist talks, etc. */
    public function scheduleEvents(): HasMany
    {
        return $this->hasMany(GalleryScheduleEvent::class)->orderBy('starts_at');
    }

    /** Newsletter signups captured in the entrance curtain */
    public function newsletterSignups(): HasMany
    {
        return $this->hasMany(NewsletterSignup::class);
    }

    /** All artists featured in this gallery (via images) */
    public function artists()
    {
        return Artist::whereHas('images', function ($q) {
            $q->where('gallery_id', $this->id);
        })->distinct()->orderBy('name');
    }

    // ─── Scopes ────────────────────────────────────────────────────────

    public function scopePubliclyViewable(Builder $q): Builder
    {
        return $q->where('is_active', true)
                 ->whereNull('pin_hash')
                 ->where(function ($q) {
                     // Not scheduled, OR currently open
                     $q->whereNull('opens_at')->orWhere('opens_at', '<=', now());
                 })
                 ->where(function ($q) {
                     $q->whereNull('closes_at')->orWhere('closes_at', '>=', now());
                 });
    }

    public function scopeWithCustomDomain(Builder $q, string $host): Builder
    {
        return $q->where('custom_domain', $host);
    }

    // ─── Accessors ─────────────────────────────────────────────────────

    public function getPublicUrlAttribute(): string
    {
        // If a custom domain is set, use it; otherwise use the standard slug URL.
        if ($this->custom_domain) {
            return 'https://' . $this->custom_domain;
        }
        return url("/gallery/{$this->slug}");
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        $img = $this->coverImage;
        return $img ? asset($img->path) : null;
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    public function hasPinProtection(): bool
    {
        return !empty($this->pin_hash);
    }

    public function hasCustomDomain(): bool
    {
        return !empty($this->custom_domain);
    }

    // --- Time-gate helpers ---

    public function isScheduled(): bool
    {
        return !is_null($this->opens_at);
    }

    public function isOpen(): bool
    {
        $now = now();

        if ($this->opens_at && $now->lt($this->opens_at)) {
            return false; // Not open yet
        }

        if ($this->closes_at && $now->gt($this->closes_at)) {
            return false; // Exhibition ended
        }

        return true;
    }

    public function hasNotOpenedYet(): bool
    {
        return $this->opens_at && now()->lt($this->opens_at);
    }

    public function hasClosed(): bool
    {
        return $this->closes_at && now()->gt($this->closes_at);
    }

    public function verifyPin(string $pin): bool
    {
        return \Hash::check($pin, $this->pin_hash);
    }
}