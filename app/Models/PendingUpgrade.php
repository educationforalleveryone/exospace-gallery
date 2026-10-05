<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PendingUpgrade extends Model
{
    use HasFactory;

    public ?string $plaintext_token = null;

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

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $plaintextToken): string
    {
        return hash('sha256', $plaintextToken);
    }

    public static function findByToken(string $plaintextToken): ?self
    {
        return static::where('token', static::hashToken($plaintextToken))->first();
    }

    public static function createForUser(User $user, string $plan, string $productId): self
    {
        $plaintextToken = self::generateToken();
        $hashedToken = self::hashToken($plaintextToken);

        $pending = self::create([
            'user_id' => $user->id,
            'token' => $hashedToken, // store the HASH, not the plaintext
            'plan' => $plan,
            'product_id' => $productId,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

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
     * Close every other open checkout the purchased plan already satisfies, so
     * duplicate clicks don't leave "awaiting payment" rows behind or trigger
     * abandoned-cart emails for an upgrade the user has already bought.
     */
    public static function expireSatisfiedBy(int $userId, string $purchasedPlan): void
    {
        $rank = config('plans.rank', ['free' => 0, 'pro' => 1, 'studio' => 2]);
        $purchasedRank = $rank[$purchasedPlan] ?? 0;

        $satisfiedPlans = array_keys(array_filter(
            $rank,
            fn (int $planRank) => $planRank > 0 && $planRank <= $purchasedRank,
        ));

        static::where('user_id', $userId)
            ->where('status', 'pending')
            ->whereIn('plan', $satisfiedPlans)
            ->update(['status' => 'expired']);
    }

    /**
     * True while this checkout still warrants a recovery email: it is open and
     * unexpired, the customer consents to marketing, and their current plan does
     * not already cover it.
     */
    public function isRecoverable(): bool
    {
        $user = $this->user;

        return $this->status === 'pending'
            && $this->expires_at?->isFuture() === true
            && $user !== null
            && $user->marketing_consent
            && $user->email_verified_at !== null
            && $user->banned_at === null
            && ! $this->isCoveredByCurrentPlan();
    }

    /**
     * A plan granted outside checkout (admin grant, another purchase) settles
     * this checkout. A trial does not: converting trial users is the point.
     */
    public function isCoveredByCurrentPlan(): bool
    {
        $user = $this->user;

        if ($user === null || $user->isInTrial()) {
            return false;
        }

        $rank = config('plans.rank', ['free' => 0, 'pro' => 1, 'studio' => 2]);

        return ($rank[$user->plan] ?? 0) >= ($rank[$this->plan] ?? 0);
    }

    public function markConverted(int $transactionId): void
    {
        $this->forceFill([
            'status' => 'converted',
            'transaction_id' => $transactionId,
        ])->save();
    }
}
