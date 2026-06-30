<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * App\Models\Artist
 *
 * An artist profile. Created and managed by a curator (User) — artists
 * do NOT have their own login (Option A, Round 4).
 *
 * Each artist can be attributed to many gallery_images across many
 * galleries. A public profile page at /artist/{slug} lists all their
 * works across all galleries.
 *
 * If we later want artists to have their own accounts (Option B),
 * add a nullable `user_id` FK and an invitation flow. The current
 * schema is forward-compatible.
 */
class Artist extends Model
{
    protected $fillable = [
        'name', 'slug', 'bio', 'portrait_path',
        'website', 'instagram', 'twitter', 'email', 'location',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $artist) {
            if (empty($artist->slug)) {
                $artist->slug = Str::slug($artist->name);
            }
            // Ensure slug uniqueness
            $base = $artist->slug;
            $i = 1;
            while (static::where('slug', $artist->slug)->exists()) {
                $artist->slug = $base . '-' . (++$i);
            }
        });
    }

    // ─── Relationships ──────────────────────────────────────────────────

    /** The curator who first created this artist profile */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** All images attributed to this artist (across all galleries) */
    public function images(): HasMany
    {
        return $this->hasMany(GalleryImage::class)->orderBy('created_at', 'desc');
    }

    /** All galleries that feature at least one work by this artist */
    public function galleries()
    {
        return Gallery::whereHas('images', function ($q) {
            $q->where('artist_id', $this->id);
        })->distinct();
    }

    // ─── Scopes ─────────────────────────────────────────────────────────

    public function scopeWithImages(Builder $q): Builder
    {
        return $q->whereHas('images');
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (!$term) return $q;
        return $q->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('bio', 'like', "%{$term}%")
              ->orWhere('location', 'like', "%{$term}%");
        });
    }

    // ─── Accessors ──────────────────────────────────────────────────────

    public function getPortraitUrlAttribute(): ?string
    {
        return $this->portrait_path
            ? asset('storage/' . $this->portrait_path)
            : null;
    }

    public function getInstagramUrlAttribute(): ?string
    {
        return $this->instagram
            ? 'https://instagram.com/' . ltrim($this->instagram, '@')
            : null;
    }

    public function getTwitterUrlAttribute(): ?string
    {
        return $this->twitter
            ? 'https://twitter.com/' . ltrim($this->twitter, '@')
            : null;
    }

    public function getInitialsAttribute(): string
    {
        $parts = explode(' ', trim($this->name));
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $initials .= strtoupper(substr($p, 0, 1));
        }
        return $initials ?: '?';
    }
}
