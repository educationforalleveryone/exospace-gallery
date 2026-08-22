<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * App\Models\PendingUpgrade
 *
 * ITERATION-8 (AUDIT-P1-8.1): Token hashing — mirrors the TeamInvitation D-6
 * pattern. Previously the `token` column stored a plaintext `Str::random(48)`
 * value, so a DB dump exposed usable upgrade tokens. Now the column stores
 * `hash('sha256', $plaintext)` and the plaintext is only held in a runtime
 * attribute (`plaintext_token`) for the duration of the request that created
 * it — passed to the 2Checkout buy URL, never persisted.
 *
 * The webhook receives the plaintext back from 2Checkout (via the
 * `external-reference` parameter) and looks it up via `findByToken()`,
 * which hashes it before querying.
 */
class PendingUpgrade extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'token',
        'plan',
        'product_id',
        'status',
        'transaction_id',
        'expires_at',
        'notified_at',
        'affiliate_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Generate a plaintext token for a new pending upgrade.
     *
     * AUDIT-P1-8.1: 64 chars (matching TeamInvitation::generateToken). The
     * plaintext is returned to the caller, who passes it to 2Checkout as
     * `external-reference`. It is NEVER stored in the database — only the
     * hash (via `hashToken()`) is persisted.
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * Hash a plaintext token for storage.
     *
     * AUDIT-P1-8.1: SHA-256 (not bcrypt) — the token is a 64-char random
     * string with high entropy, so a fast hash is sufficient. Bcrypt would
     * add ~100ms per lookup (unacceptable for webhook latency). SHA-256 is
     * constant-time and produces a 64-char hex string that fits the existing
     * `string('token', 64)` column.
     */
    public static function hashToken(string $plaintextToken): string
    {
        return hash('sha256', $plaintextToken);
    }

    /**
     * Find a pending upgrade by its plaintext token.
     *
     * AUDIT-P1-8.1: Hashes the plaintext before querying. Returns null if
     * no match — callers must handle the null case (e.g. fall back to
     * customer_email lookup).
     *
     * NOTE: This method does NOT filter by status — callers should add
     * `->where('status', 'pending')` if they only want active pending
     * upgrades (the WebhookController does this).
     */
    public static function findByToken(string $plaintextToken): ?self
    {
        return static::where('token', static::hashToken($plaintextToken))->first();
    }

    /**
     * Generate a new pending upgrade for a user + plan.
     *
     * AUDIT-P1-8.1: Stores the HASHED token in the DB. The plaintext is
     * attached as a runtime attribute (`plaintext_token`) so the caller
     * (BillingController::upgrade) can pass it to the 2Checkout buy URL.
     * The plaintext is NEVER persisted — it exists only for this request.
     *
     * @param  User    $user
     * @param  string  $plan        'pro' | 'studio'
     * @param  string  $productId   2Checkout product ID
     * @return self                 The model instance. Access `$model->plaintext_token`
     *                              for the plaintext token to pass to 2Checkout.
     *                              Do NOT read `$model->token` — that's the hash.
     */
    public static function createForUser(User $user, string $plan, string $productId): self
    {
        $plaintextToken = self::generateToken();
        $hashedToken = self::hashToken($plaintextToken);

        $pending = self::create([
            'user_id'    => $user->id,
            'token'      => $hashedToken, // AUDIT-P1-8.1: store the HASH, not the plaintext
            'plan'       => $plan,
            'product_id' => $productId,
            'status'     => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        // Attach the plaintext as a runtime attribute (non-persisted) so the
        // caller can use it in the 2Checkout buy URL. Mirrors the TeamInvitation
        // D-6 pattern (Admin/TeamController.php line 164).
        $pending->plaintext_token = $plaintextToken;

        return $pending;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Mark this pending upgrade as converted by the given transaction.
     */
    public function markConverted(int $transactionId): void
    {
        $this->forceFill([
            'status'         => 'converted',
            'transaction_id' => $transactionId,
        ])->save();
    }
}
