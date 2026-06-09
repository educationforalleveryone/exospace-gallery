<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_super_admin',
        'plan',
        'max_galleries',
        'max_images',
        'plan_started_at',
        'plan_expires_at',
        'current_team_id',   // ← NEW: active team context
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_super_admin'    => 'boolean',
        ];
    }

    // ── Existing relationships ────────────────────────────────────────────

    /** Galleries the user personally owns */
    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }

    // ── Team relationships ────────────────────────────────────────────────

    /** Teams this user owns */
    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'owner_id');
    }

    /** Teams this user belongs to (including ones they own via pivot) */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    /** The currently active team */
    public function currentTeam()
    {
        if (! $this->current_team_id) {
            return null;
        }
        return Team::find($this->current_team_id);
    }

    /** Switch the active team context */
    public function switchTeam(Team $team): bool
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }
        $this->forceFill(['current_team_id' => $team->id])->save();
        return true;
    }

    public function belongsToTeam(Team $team): bool
    {
        return $team->isOwner($this) || $team->hasMember($this);
    }

    public function teamRole(Team $team): ?string
    {
        if ($team->isOwner($this)) return 'owner';
        return $team->memberRole($this);
    }

    // ── Plan helpers ──────────────────────────────────────────────────────

    /**
     * Canonical plan → limit map. Single source of truth for the whole app.
     */
    public static function planLimits(string $plan): array
    {
        return match($plan) {
            'pro', 'studio' => ['max_galleries' => 999, 'max_images' => 100],
            default         => ['max_galleries' => 1,   'max_images' => 10],
        };
    }

    public function isPro(): bool
    {
        return in_array($this->plan, ['pro', 'studio']);
    }

    public function canCreateGallery(): bool
    {
        // DB-level count to avoid race conditions in concurrent requests
        return \DB::table('galleries')
            ->where('user_id', $this->id)
            ->whereNull('team_id')
            ->count() < $this->max_galleries;
    }

    public function currentImageCount(): int
    {
        return \DB::table('gallery_images')
            ->join('galleries', 'galleries.id', '=', 'gallery_images.gallery_id')
            ->where('galleries.user_id', $this->id)
            ->count();
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    // ── Boot ──────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::created(function (User $user) {
            // Skip welcome email for invitation-created accounts.
            // They already have email_verified_at set, meaning they came
            // through the invite flow and will get a team-specific welcome instead.
            if ($user->email_verified_at !== null) {
                return;
            }

            try {
                \Illuminate\Support\Facades\Mail::to($user->email)
                    ->send(new \App\Mail\WelcomeEmail($user));
                \Illuminate\Support\Facades\Log::info("Welcome email sent to: {$user->email}");
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send welcome email: ' . $e->getMessage());
            }
        });
    }
}
