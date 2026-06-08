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
        'floor_material', 'audio_path', 'custom_logo_path',
        'room_layout', 'pin_hash',
        'is_active', 'view_count',
        'opens_at', 'closes_at',
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
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(GalleryImage::class)->orderBy('position_order');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GalleryEvent::class);
    }

    public function getPublicUrlAttribute(): string
    {
        return url("/gallery/{$this->slug}");
    }

    public function hasPinProtection(): bool
    {
        return !empty($this->pin_hash);
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