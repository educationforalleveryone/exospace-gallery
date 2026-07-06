<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;

/**
 * M-12: In-app notification service.
 *
 * Creates and manages per-user notifications shown in the navigation bell.
 *
 * Usage:
 *   NotificationService::create($user, 'billing', 'Payment successful', 'Your Pro plan is now active.', '/billing');
 *   NotificationService::create($user, 'subscription', 'Subscription cancelled', 'Access until ' . $date, '/billing');
 *
 * Notification types:
 *   - billing       — payment success/failure, refund
 *   - subscription  — subscription start/cancel/reactivate
 *   - dunning       — payment failed warnings (mirrors the dunning emails)
 *   - system        — system announcements, maintenance
 *   - gallery       — gallery toggled, featured
 *   - team          — team invitation received
 */
class NotificationService
{
    /**
     * Create a notification for a user.
     *
     * @param  User       $user
     * @param  string     $type         Notification category
     * @param  string     $title        Short headline
     * @param  string|null $body        Longer description
     * @param  string|null $actionUrl   Link to relevant page
     * @param  string|null $actionLabel Text for the link
     * @return UserNotification|null
     */
    public static function create(
        User $user,
        string $type,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
    ): ?UserNotification {
        try {
            return UserNotification::create([
                'user_id'      => $user->id,
                'type'         => $type,
                'title'        => $title,
                'body'         => $body,
                'action_url'   => $actionUrl,
                'action_label' => $actionLabel,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotificationService: failed to create notification', [
                'user_id' => $user->id,
                'type'    => $type,
                'title'   => $title,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Mark a single notification as read.
     */
    public static function markAsRead(UserNotification $notification): void
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }
    }

    /**
     * Mark all unread notifications for a user as read.
     */
    public static function markAllAsRead(User $user): int
    {
        return UserNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Get the unread notification count for a user.
     * Used by the bell icon badge.
     */
    public static function unreadCount(User $user): int
    {
        return UserNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Get recent notifications for a user (for the dropdown).
     * Returns the 10 most recent, mixed read + unread.
     */
    public static function recent(User $user, int $limit = 10)
    {
        return UserNotification::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
