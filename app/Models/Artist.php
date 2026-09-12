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
    use HasSeoProfile; // SEO OS (Iteration 1) — admin overrides via seo_profiles
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
        });
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
