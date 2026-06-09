<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VenueTemplate extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'thumbnail',
        'plan_required', 'capacity_min', 'capacity_max',
        'default_settings', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'default_settings' => 'array',
        'is_active'        => 'boolean',
        'capacity_max'     => 'integer',
    ];

    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }

    public function isAccessibleBy(User $user): bool
    {
        return match($this->plan_required) {
            'free'   => true,
            'pro'    => $user->isPro(),
            'studio' => $user->plan === 'studio',
            default  => false,
        };
    }

    public function capacityLabel(): string
    {
        if (is_null($this->capacity_max)) {
            return 'Unlimited';
        }
        return "{$this->capacity_min}–{$this->capacity_max} artworks";
    }

    public static function forUser(User $user)
    {
        return static::where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }
}