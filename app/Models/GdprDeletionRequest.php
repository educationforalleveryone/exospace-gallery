<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GdprDeletionRequest extends Model
{
    use HasFactory;

    protected $table = 'gdpr_deletion_requests';

    protected $fillable = [
        'user_id',
        'email',
        'status',
        'requester_ip',
        'admin_actor_id',
        'requested_at',
        'scheduled_deletion_at',
        'completed_at',
        'reason',
    ];

    protected $casts = [
        'requested_at'           => 'datetime',
        'scheduled_deletion_at'  => 'datetime',
        'completed_at'           => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adminActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_actor_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isScheduledForDeletion(): bool
    {
        return $this->status === 'pending'
            && $this->scheduled_deletion_at
            && $this->scheduled_deletion_at->isPast();
    }

    public static function createForUser(User $user, ?string $reason = null, ?string $ip = null): self
    {
        return static::create([
            'user_id'                => $user->id,
            'email'                  => $user->email,
            'status'                 => 'pending',
            'requester_ip'           => $ip,
            'requested_at'           => now(),
            'scheduled_deletion_at'  => now()->addDays(30),
            'reason'                 => $reason,
        ]);
    }
}
