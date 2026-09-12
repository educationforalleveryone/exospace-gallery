<?php

declare(strict_types=1);

namespace App\Ops\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsAccessGrant extends Model
{
    public const LEVEL_VIEWER = 'viewer';

    public const LEVEL_OPERATOR = 'operator';

    public const LEVELS = [self::LEVEL_VIEWER, self::LEVEL_OPERATOR];

    protected $table = 'ops_access_grants';

    protected $fillable = [
        'user_id', 'level', 'granted_by', 'granted_at', 'revoked_at',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function scopeActiveViewers(Builder $query): Builder
    {
        return $query->active()->where('level', self::LEVEL_VIEWER);
    }

    public function scopeActiveOperators(Builder $query): Builder
    {
        return $query->active()->where('level', self::LEVEL_OPERATOR);
    }

    public function scopeActiveGranted(Builder $query): Builder
    {
        return $query->active()->whereIn('level', self::LEVELS);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && in_array($this->level, self::LEVELS, true);
    }

    public static function hasActiveViewerGrant(User $user): bool
    {
        return static::hasActiveGrant($user, [self::LEVEL_VIEWER]);
    }

    public static function hasActiveGrant(User $user, array $levels): bool
    {
        return static::query()
            ->active()
            ->whereIn('level', $levels)
            ->where('user_id', $user->id)
            ->exists();
    }

    public static function activeLevelFor(User $user): ?string
    {
        $level = static::query()
            ->active()
            ->whereIn('level', self::LEVELS)
            ->where('user_id', $user->id)
            ->value('level');

        return is_string($level) ? $level : null;
    }
}
