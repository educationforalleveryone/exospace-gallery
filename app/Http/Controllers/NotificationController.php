<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * M-12: Notification controller — handles mark-as-read + mark-all-as-read.
 */
class NotificationController extends Controller
{
    /**
     * Mark a single notification as read + redirect to its action_url (if any).
     */
    public function markRead(Request $request, UserNotification $notification): RedirectResponse
    {
        // Authorization: only the notification's owner can mark it read
        if ($notification->user_id !== $request->user()->id) {
            abort(403);
        }

        NotificationService::markAsRead($notification);

        // Redirect to the action URL if one exists, otherwise back
        if ($notification->action_url) {
            return redirect($notification->action_url);
        }

        return back();
    }

    /**
     * Mark all unread notifications as read.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        NotificationService::markAllAsRead($request->user());

        return back()->with('status', 'All notifications marked as read.');
    }
}
