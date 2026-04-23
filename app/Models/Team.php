<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Team extends Model
{
    protected $fillable = ['owner_id', 'name', 'slug', 'description'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = Str::slug($team->name) . '-' . Str::random(6);
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** All members including the owner (via pivot) */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    public function memberRole(User $user): ?string
    {
        $pivot = $this->members()->where('user_id', $user->id)->first();
        return $pivot?->pivot->role;
    }

    public function isOwner(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    public function canEdit(User $user): bool
    {
        $role = $this->memberRole($user);
        return in_array($role, ['owner', 'editor']);
    }
}
