<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Gallery extends Model
{
    protected $fillable = [
        'user_id', 'title', 'slug', 'description',
        'wall_texture', 'frame_style', 'lighting_preset',
        'floor_material', 'audio_path', 'custom_logo_path',  // ← ADDED THESE TWO
        'is_active', 'view_count'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'view_count' => 'integer',
    ];

    // Auto-generate slug on creation
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($gallery) {
            if (empty($gallery->slug)) {
                $gallery->slug = Str::slug($gallery->title) . '-' . uniqid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(GalleryImage::class)->orderBy('position_order');
    }
    
    // Helper to get public URL
    public function getPublicUrlAttribute(): string
    {
        return url("/gallery/{$this->slug}");
    }
}