<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;

class NotificationService
{
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

    public static function markAsRead(UserNotification $notification): void
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }
    }

    public static function markAllAsRead(User $user): int
    {
        return UserNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public static function unreadCount(User $user): int
    {
        return UserNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public static function recent(User $user, int $limit = 10)
    {
        return UserNotification::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
