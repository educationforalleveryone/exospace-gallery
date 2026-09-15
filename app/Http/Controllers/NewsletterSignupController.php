<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\NewsletterSignup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NewsletterSignupController extends Controller
{
    public function __construct(
        private readonly \App\Services\TurnstileService $turnstile,
    ) {}

    public function store(Request $request, string $slug): JsonResponse
    {
        $gallery = Gallery::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255', 'email'],
            'name'  => ['nullable', 'string', 'max:100'],
        ]);

        if (! $this->turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            return response()->json([
                'success' => false,
                'error'   => 'Captcha verification failed. Please refresh and try again.',
            ], 422);
        }

        // Idempotent — unique constraint on (gallery_id, email). A concurrent
        // duplicate submit can lose the race between the existence check and
        // the insert; the unique index then rejects it, which maps to the
        // same "already on the list" outcome.
        $signup = null;
        $isNew = false;

        try {
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
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $signup = NewsletterSignup::where('gallery_id', $gallery->id)
                ->where('email', $validated['email'])
                ->first();
        }

        return response()->json([
            'success'  => true,
            'is_new'   => $isNew,
            'message'  => $isNew
                ? "You're on the list! We'll let {$gallery->user->name} know you're interested."
                : "You're already on the list — see you soon!",
        ]);
    }
}
