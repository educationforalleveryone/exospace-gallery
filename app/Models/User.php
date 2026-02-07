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
        'is_super_admin',  // ← ADD THIS LINE
        'plan',
        'max_galleries',
        'max_images',
        'plan_started_at',
        'plan_expires_at',
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

    /**
     * Check if user is a super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    /**
     * Boot method to trigger events
     */
    protected static function booted(): void
    {
        static::created(function (User $user) {
            // Send welcome email when user registers
            try {
                \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\WelcomeEmail($user));
                \Illuminate\Support\Facades\Log::info("Welcome email sent to: {$user->email}");
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send welcome email: ' . $e->getMessage());
            }
        });
    }
}