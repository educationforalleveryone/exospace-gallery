<?php

namespace App\Models;

use App\Models\Concerns\HasSeoProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Artist extends Model
{
    use HasFactory;
    use HasSeoProfile; // SEO OS — admin overrides via seo_profiles
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

        // The slug column carries a unique index; collisions and empty
        // values (non-ASCII-only names slugify to nothing) must never reach
        // the database, otherwise artist creation fails with a query error
        // and the public profile route has no resolvable identifier.
        static::saving(function (self $artist) {
            if (empty($artist->slug)) {
                $artist->slug = $artist->buildUniqueSlug(Str::slug($artist->name) ?: 'artist');
            }
        });
    }

    private function buildUniqueSlug(string $base): string
    {
        $base = Str::limit($base, 120, '');
        $slug = $base;

        $attempt = 2;
        while ($this->slugTaken($slug)) {
            $slug = $base . '-' . $attempt++;
        }

        return $slug;
    }

    private function slugTaken(string $slug): bool
    {
        return static::query()
            ->where('slug', $slug)
            ->when($this->exists, fn (Builder $q) => $q->whereKeyNot($this->getKey()))
            ->exists();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function images(): HasMany
    {
        return $this->hasMany(GalleryImage::class)->orderBy('created_at', 'desc');
    }

    public function galleries(): BelongsToMany
    {
        return $this->belongsToMany(Gallery::class, 'gallery_images')->distinct();
    }

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

    public function getPortraitUrlAttribute(): ?string
    {
        return $this->portrait_path
            ? asset('storage/' . $this->portrait_path)
            : null;
    }

    // Only expose http(s) targets on public pages; the `url` validation rule
    // also accepts exotic schemes (e.g. javascript:) that must never become
    // clickable links on the artist profile.
    public function getWebsiteUrlAttribute(): ?string
    {
        if (!$this->website) {
            return null;
        }

        $scheme = strtolower((string) parse_url($this->website, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $this->website : null;
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
            $initials .= mb_strtoupper(mb_substr($p, 0, 1));
        }
        return $initials ?: '?';
    }
}
