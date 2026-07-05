<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    use HasFactory;
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
            // P2-5 FIX: Removed the while-loop slug uniqueness check.
            // The DB unique constraint on 'slug' is the source of truth.
            // If two concurrent creates produce the same slug, one will
            // throw a QueryException (duplicate key) — the controller
            // catches it and retries with an incremented slug.
            // The while-loop was a TOCTOU race: both requests pass the
            // check, one fails on the unique constraint.
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

    /**
     * All galleries that feature at least one work by this artist.
     *
     * P2-4 FIX: Previously returned a query builder via whereHas, which
     * broke eager loading (with('galleries')), withCount, and lazy loading.
     * Now uses belongsToMany through the gallery_images pivot table —
     * supports standard Eloquent relationship operations.
     */
    public function galleries(): BelongsToMany
    {
        return $this->belongsToMany(Gallery::class, 'gallery_images')->distinct();
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
