<?php

declare(strict_types=1);

namespace App\Ops\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Ops\Models\OpsAccessGrant
 *
 * One row per "give this user access to OpsCenter" decision (Iteration 5).
 * Active = revoked_at IS NULL and level is a known tier. Revocation never
 * deletes — the ledger keeps who granted what, when, and who took it away.
 *
 * Levels (Iteration 6 adds the operator tier on the column Iteration 5
 * deliberately reserved):
 *   viewer   — read-only: overview, applications, errors, incidents,
 *              diagnostics RESULTS. The Iteration-5 behavior, unchanged.
 *   operator — everything the viewer sees PLUS running the read-only
 *              diagnostics (POST /ops/diagnostics/run). Never the Actions
 *              hub, never credentials, never access management — those
 *              stay super-admin-only at the route level.
 *
 * The middleware (EnsureOpsAccess / EnsureOpsOperator) are the ONLY
 * consumers of this model at request time; the management UI lives in
 * OpsAccessController. Unknown levels grant nothing (fail-closed).
 */
class OpsAccessGrant extends Model
{
    /** Read-only access (Iteration 5). */
    public const LEVEL_VIEWER = 'viewer';

    /** Read + run read-only diagnostics (Iteration 6). */
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

    // ── Scopes ──────────────────────────────────────────────────────────

    /** Grants that currently open the door. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /** The viewer-level active grants specifically. */
    public function scopeActiveViewers(Builder $query): Builder
    {
        return $query->active()->where('level', self::LEVEL_VIEWER);
    }

    /** The operator-level active grants specifically. */
    public function scopeActiveOperators(Builder $query): Builder
    {
        return $query->active()->where('level', self::LEVEL_OPERATOR);
    }

    /** Active grants at ANY known level (the read-surface gate). */
    public function scopeActiveGranted(Builder $query): Builder
    {
        return $query->active()->whereIn('level', self::LEVELS);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->revoked_at === null && in_array($this->level, self::LEVELS, true);
    }

    /**
     * Does this user hold an active viewer grant? Single indexed lookup —
     * safe to call per request. Returns false for super-admins too: they
     * don't need grants; access comes from is_super_admin.
     */
    public static function hasActiveViewerGrant(User $user): bool
    {
        return static::hasActiveGrant($user, [self::LEVEL_VIEWER]);
    }

    /**
     * Does this user hold an active grant at one of the given levels?
     * (Iteration 6 generalization of hasActiveViewerGrant — one indexed
     * lookup, fail-closed for unknown levels.)
     *
     * @param  array<int, string>  $levels
     */
    public static function hasActiveGrant(User $user, array $levels): bool
    {
        return static::query()
            ->active()
            ->whereIn('level', $levels)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * The user's ACTIVE grant level, or null when they hold none (or hold
     * an unknown/future level — fail-closed). Views and middleware use
     * this to distinguish what to render/enforce per tier.
     */
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
