<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Generate a new pending upgrade for a user + plan.
     *
     * @param  User    $user
     * @param  string  $plan        'pro' | 'studio'
     * @param  string  $productId   2Checkout product ID
     * @return self
     */
    public static function createForUser(User $user, string $plan, string $productId): self
    {
        return self::create([
            'user_id'    => $user->id,
            'token'      => Str::random(48),
            'plan'       => $plan,
            'product_id' => $productId,
            'status'     => 'pending',
            'expires_at' => now()->addDays(7),
        ]);
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
     * Is this pending upgrade still claimable (pending + not expired)?
     */
    public function isClaimable(): bool
    {
        return $this->status === 'pending'
            && ($this->expires_at === null || $this->expires_at->isFuture());
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
