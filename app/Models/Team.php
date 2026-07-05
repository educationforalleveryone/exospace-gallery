<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Team extends Model
{
    use HasFactory;
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
        // PERF-19 FIX: Memoize per-request so multiple canEdit() / hasMember()
        // calls on the same Team + User don't re-query the pivot each time.
        //
        // Why memoize (not Cache::remember): team membership can change
        // mid-request (e.g., the TeamController::invite path that mutates
        // the pivot, then immediately calls canEdit). A persistent cache
        // would serve stale data for up to its TTL. The static array is
        // per-request only (PHP request lifecycle), so it's always fresh
        // within a request and never serves stale data across requests.
        //
        // The query itself is one indexed lookup on team_user (team_id, user_id)
        // — sub-millisecond — but on the admin dashboard it fires 6+ times per
        // request (gallery list → GalleryPolicy::update → canEdit → memberRole
        // for each of 6 recent galleries). Memoizing collapses those into 1
        // query per (team, user) pair.
        static $cache = [];
        $key = "{$this->id}:{$user->id}";

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $pivot = $this->members()->where('user_id', $user->id)->first();
        return $cache[$key] = $pivot?->pivot->role;
    }

    public function isOwner(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    public function canEdit(User $user): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }
        $role = $this->memberRole($user);
        return in_array($role, ['editor']);
    }
}
