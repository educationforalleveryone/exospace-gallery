<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Relationship: A user creates many galleries
    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }

    // Plan helpers
    public function isPro(): bool
    {
        return in_array($this->plan, ['pro', 'studio']);
    }

    public function canCreateGallery(): bool
    {
        return $this->galleries()->count() < $this->max_galleries;
    }
}