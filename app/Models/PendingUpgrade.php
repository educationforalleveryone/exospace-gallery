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
            'user_id'    => $user->id,
            'token'      => $hashedToken, // store the HASH, not the plaintext
            'plan'       => $plan,
            'product_id' => $productId,
            'status'     => 'pending',
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

    public function markConverted(int $transactionId): void
    {
        $this->forceFill([
            'status'         => 'converted',
            'transaction_id' => $transactionId,
        ])->save();
    }
}
