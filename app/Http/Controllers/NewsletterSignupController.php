<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\NewsletterSignup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Newsletter signup endpoint.
 *
 * Route: POST /gallery/{slug}/newsletter
 *
 * Called from the entrance curtain in view.blade.php before the visitor
 * enters the gallery. Captures email + optional name, attributed to the
 * gallery so the curator can see their audience in analytics.
 *
 * Idempotent: unique on (gallery_id, email) — repeat signups are silent
 * successes (no error to the visitor, no duplicate row).
 */
class NewsletterSignupController extends Controller
{
    public function store(Request $request, string $slug): JsonResponse
    {
        $gallery = Gallery::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255', 'email'],
            'name'  => ['nullable', 'string', 'max:100'],
        ]);

        // Idempotent — unique constraint on (gallery_id, email)
        $signup = NewsletterSignup::firstOrCreate(
            [
                'gallery_id' => $gallery->id,
                'email'      => $validated['email'],
            ],
            [
                'name'      => $validated['name'] ?? null,
                'ip_address' => $request->ip(),
                'referrer'  => $request->header('referer'),
            ]
        );

        $isNew = $signup->wasRecentlyCreated;

        return response()->json([
            'success'  => true,
            'is_new'   => $isNew,
            'message'  => $isNew
                ? "You're on the list! We'll let {$gallery->user->name} know you're interested."
                : "You're already on the list — see you soon!",
        ]);
    }
}
