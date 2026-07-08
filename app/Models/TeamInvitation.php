<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * TeamInvitation model.
 *
 * ITERATION-004 FIX (audit D-6): Tokens are now HASHED at rest.
 *
 * Previously, tokens were 64-char random strings stored as-is in the DB.
 * If the DB was dumped, an attacker could accept all pending invitations
 * (and gain team access). Laravel's API tokens (Sanctum) are hashed at
 * rest; invitation tokens should be too.
 *
 * The token is stored as hash('sha256', $plaintext) in the `token` column.
 * The raw plaintext token is passed in the email link. Lookups use:
 *   TeamInvitation::where('token', hash('sha256', $rawToken))->firstOrFail()
 *
 * The generateToken() static method returns the PLAINTEXT token (for use
 * in email links). The calling code must hash it before storing or
 * querying.
 */
class TeamInvitation extends Model
{
    use HasFactory;

    protected $fillable = ['team_id', 'email', 'role', 'token', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Generate a fresh invitation token (PLAINTEXT — for use in email links).
     *
     * The caller must hash this token via TeamInvitation::hashToken() before
     * storing it in the DB or querying for it.
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * D-6 FIX (Iter-004): Hash a plaintext token for storage or lookup.
     *
     * Uses sha256 (not bcrypt) because:
     *   - The token is 64 chars of randomness (384 bits of entropy) — no
     *     need for a slow hash to resist brute-force.
     *   - sha256 is fast, which is what we want for lookup queries.
     *   - bcrypt would make the lookup query impossible (can't compare
     *     bcrypt hashes in a WHERE clause).
     *
     * @param  string  $plaintextToken  The raw token from the email link
     * @return string  The sha256 hash for storage/lookup
     */
    public static function hashToken(string $plaintextToken): string
    {
        return hash('sha256', $plaintextToken);
    }

    /**
     * D-6 FIX (Iter-004): Find an invitation by plaintext token.
     *
     * Convenience method that hashes the token and queries.
     * Returns null if not found (use firstOrFail() via the query builder
     * if you want a 404).
     */
    public static function findByToken(string $plaintextToken): ?self
    {
        return static::where('token', static::hashToken($plaintextToken))->first();
    }
}
