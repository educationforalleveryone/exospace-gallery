<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\GalleryScheduleEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Services\TurnstileService;
use Illuminate\Http\RedirectResponse;

/**
 * Public-facing gallery events page + RSVP form.
 *
 * Routes:
 *   GET  /gallery/{slug}/events          — list upcoming events
 *   POST /gallery/{slug}/events/{event}/rsvp — submit RSVP
 */
class PublicEventController extends Controller
{
    public function __construct(
        private readonly TurnstileService $turnstile,
    ) {}

    public function index(string $slug): View
    {
        $gallery = Gallery::where('slug', $slug)
            ->where('is_active', true)
            ->with(['scheduleEvents' => function ($q) {
                $q->active()->orderBy('starts_at')->withCount('rsvps'); // PERF-15: eager-load rsvps_count
            }, 'venueTemplate'])
            ->firstOrFail();

        $upcoming = $gallery->scheduleEvents->filter(fn ($e) => $e->isUpcoming());
        $past = $gallery->scheduleEvents->filter(fn ($e) => $e->isPast())->take(5);

        return view('gallery.events', compact('gallery', 'upcoming', 'past'));
    }

    public function rsvp(Request $request, string $slug, GalleryScheduleEvent $event): RedirectResponse
    {
        $gallery = Gallery::where('slug', $slug)->where('is_active', true)->firstOrFail();
        if ($event->gallery_id !== $gallery->id) abort(404);
        if (!$event->is_active || $event->isPast()) {
            return back()->with('error', 'This event is no longer accepting RSVPs.');
        }

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'max:255', 'email'],
        ]);

        // P3-19: Verify Turnstile captcha if enabled.
        if (! $this->turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            return back()->withErrors(['captcha' => 'Captcha verification failed. Please refresh and try again.'])->withInput();
        }

        // Enforce capacity
        if ($event->isAtCapacity()) {
            return back()->with('error', 'This event has reached capacity.');
        }

        // Idempotent: unique on (schedule_event_id, email)
        \App\Models\EventRsvp::firstOrCreate(
            [
                'schedule_event_id' => $event->id,
                'email'             => $validated['email'],
            ],
            [
                'name'        => $validated['name'],
                'ip_address'  => $request->ip(),
                'confirmed_at' => now(),
            ]
        );

        // Send curator an email notification
        try {
            \Illuminate\Support\Facades\Mail::to($gallery->user->email)
                ->send(new \App\Mail\EventRsvpNotification($gallery, $event, $validated));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to send RSVP notification: ' . $e->getMessage());
        }

        return back()->with('status', "You're RSVP'd for \"{$event->title}\". We'll see you there!");
    }
}
