<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M-19: User feedback model.
 *
 * Stores feedback from the in-app feedback widget. Created by
 * FeedbackController::store(), viewed by super-admins in the
 * feedback admin panel.
 */
class UserFeedback extends Model
{
    use HasFactory;

    protected $table = 'user_feedback';

    protected $fillable = [
        'user_id',
        'category',
        'message',
        'page_url',
        'user_agent',
        'status',
    ];

    public const CATEGORIES = [
        'bug'             => '🐛 Bug Report',
        'feature_request' => '💡 Feature Request',
        'praise'          => '❤️ Praise',
        'other'           => '💬 Other',
    ];

    public const STATUSES = [
        'new'      => 'New',
        'reviewed' => 'Reviewed',
        'resolved' => 'Resolved',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
