<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
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

    public function markAllRead(Request $request): RedirectResponse
    {
        NotificationService::markAllAsRead($request->user());

        return back()->with('status', 'All notifications marked as read.');
    }
}
