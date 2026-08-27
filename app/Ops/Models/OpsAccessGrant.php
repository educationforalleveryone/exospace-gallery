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
 * Active = revoked_at IS NULL and level = 'viewer'. Revocation never
 * deletes — the ledger keeps who granted what, when, and who took it away.
 *
 * The middleware (EnsureOpsAccess) is the ONLY consumer of this model at
 * request time; the management UI lives in OpsAccessController.
 */
class OpsAccessGrant extends Model
{
    /** The only level that grants anything today. Fail-closed for others. */
    public const LEVEL_VIEWER = 'viewer';

    public const LEVELS = [self::LEVEL_VIEWER];

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

    // ── Helpers ─────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->level === self::LEVEL_VIEWER;
    }

    /**
     * Does this user hold an active viewer grant? Single indexed lookup —
     * safe to call per request. Returns false for super-admins too: they
     * don't need grants; access comes from is_super_admin.
     */
    public static function hasActiveViewerGrant(User $user): bool
    {
        return static::query()
            ->activeViewers()
            ->where('user_id', $user->id)
            ->exists();
    }
}
