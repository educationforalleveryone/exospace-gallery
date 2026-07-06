<?php

namespace App\Http\Controllers;

use App\Models\UserFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * M-19: Feedback controller.
 *
 * Handles:
 *   - POST /feedback — store feedback from the widget (AJAX or form POST)
 *   - GET /admin/feedback — super-admin view of all feedback (for triage)
 *   - PATCH /admin/feedback/{feedback}/status — update feedback status
 */
class FeedbackController extends Controller
{
    /**
     * Store feedback from the in-app widget.
     * Accepts both AJAX (JSON response) and regular form POST (redirect back).
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(UserFeedback::CATEGORIES))],
            'message'  => ['required', 'string', 'max:5000'],
        ]);

        $user = $request->user();

        try {
            UserFeedback::create([
                'user_id'    => $user?->id,
                'category'   => $validated['category'],
                'message'    => $validated['message'],
                'page_url'   => $request->header('Referer'),
                'user_agent' => $request->header('User-Agent'),
                'status'     => 'new',
            ]);

            // M-12: Create in-app notification for super-admins
            if ($user) {
                \App\Models\AdminAuditLog::record('feedback_received', $user, [
                    'category' => $validated['category'],
                    'preview'  => mb_substr($validated['message'], 0, 100),
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('FeedbackController: store failed', [
                'error' => $e->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json(['error' => 'Failed to submit feedback. Please try again.'], 500);
            }
            return back()->withErrors(['feedback' => 'Failed to submit feedback.']);
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Thank you for your feedback!']);
        }

        return back()->with('status', 'Thank you for your feedback!');
    }

    /**
     * Super-admin: list all feedback for triage.
     */
    public function index(Request $request): View
    {
        $query = UserFeedback::with('user')->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $feedback = $query->paginate(25)->withQueryString();
        $counts = [
            'all'      => UserFeedback::count(),
            'new'      => UserFeedback::where('status', 'new')->count(),
            'reviewed' => UserFeedback::where('status', 'reviewed')->count(),
            'resolved' => UserFeedback::where('status', 'resolved')->count(),
        ];

        return view('super-admin.feedback.index', compact('feedback', 'counts'));
    }

    /**
     * Super-admin: update feedback status.
     */
    public function updateStatus(Request $request, UserFeedback $feedback): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:new,reviewed,resolved'],
        ]);

        $feedback->update(['status' => $validated['status']]);

        return back()->with('status', 'Feedback status updated.');
    }
}
