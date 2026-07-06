<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M-12: In-app notification model.
 *
 * Each notification belongs to a user and has a type, title, body, and
 * optional action link. Unread notifications have read_at = null.
 *
 * Created by NotificationService::create() and displayed in the navigation
 * bell dropdown.
 */
class UserNotification extends Model
{
    use HasFactory;

    protected $table = 'user_notifications';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'action_url',
        'action_label',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Is this notification unread?
     */
    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * Scope to only unread notifications.
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
