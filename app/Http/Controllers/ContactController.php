<?php

namespace App\Http\Controllers;

use App\Services\TurnstileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    public function __construct(
        private readonly TurnstileService $turnstile,
    ) {}

    public function submit(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:100',
            'email'   => 'required|email|max:255',
            'subject' => 'nullable|string|max:200',
            'message' => 'required|string|max:5000',
        ]);

        // P3-19: Verify Turnstile captcha if enabled. When TURNSTILE_SITE_KEY
        // is not set, TurnstileService::verify() returns true (dev mode).
        if (! $this->turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Captcha verification failed. Please refresh and try again.'], 422);
            }
            return back()->withErrors(['captcha' => 'Captcha verification failed. Please refresh and try again.'])->withInput();
        }

        try {
            Mail::raw(
                "Name: {$validated['name']}\nEmail: {$validated['email']}\nSubject: " . ($validated['subject'] ?? 'No subject') . "\n\n{$validated['message']}",
                function ($msg) use ($validated) {
                    $msg->to(config('mail.from.address'))
                        ->subject('[Exospace Contact] ' . ($validated['subject'] ?? 'New message from ' . $validated['name']))
                        ->replyTo($validated['email'], $validated['name']);
                }
            );

            Log::info('Contact form submitted', ['email' => $validated['email']]);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Message sent successfully.']);
            }

            return back()->with('status', 'Thanks! We\'ll get back to you shortly.');

        } catch (\Exception $e) {
            Log::error('Contact form failed: ' . $e->getMessage());

            if ($request->expectsJson()) {
                return response()->json(['error' => 'Failed to send message.'], 500);
            }

            return back()->withErrors(['message' => 'Could not send your message. Please try again.']);
        }
    }
}