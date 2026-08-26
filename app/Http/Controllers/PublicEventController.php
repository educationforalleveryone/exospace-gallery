<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\GalleryScheduleEvent;
use App\Support\Seo\Breadcrumb;
use App\Support\Seo\SeoManager;
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
 *
 * ITERATION-3 FIX (P0 leak): both routes previously served PIN-protected
 * galleries to anyone — event titles, dates, capacities and the RSVP form
 * were fully public even though the exhibition itself sits behind the PIN
 * gate, and a POST could submit an RSVP without ever entering the PIN.
 * Both now demand the same session PIN verification the gallery view
 * uses. A closed exhibition redirects to its own "closed" page, matching
 * the gallery view's time-gate behaviour. (Not-yet-open exhibitions keep
 * their events page public on purpose: openings and artist talks are the
 * pre-opening marketing surface — that is what RSVPs are FOR.)
 */
class PublicEventController extends Controller
{
    public function __construct(
        private readonly TurnstileService $turnstile,
        private readonly SeoManager $seo,
    ) {}

    public function index(string $slug): View|\Illuminate\Http\RedirectResponse
    {
        $gallery = Gallery::where('slug', $slug)
            ->where('is_active', true)
            ->with(['scheduleEvents' => function ($q) {
                $q->active()->orderBy('starts_at')->withCount('rsvps'); // PERF-15: eager-load rsvps_count
            }, 'venueTemplate'])
            ->firstOrFail();

        // ITERATION-3: closed exhibitions defer to their own closed page.
        if ($gallery->hasClosed()) {
            return redirect()->route('gallery.view', $gallery->slug);
        }

        // ITERATION-3: PIN gate — mirrors the gallery view (events data is
        // exhibition content; the PIN screen is the single entry point).
        if ($gallery->hasPinProtection() && ! session("pin_verified_{$gallery->id}")) {
            return redirect()->route('gallery.pin', $gallery->slug);
        }

        $upcoming = $gallery->scheduleEvents->filter(fn ($e) => $e->isUpcoming());
        $past = $gallery->scheduleEvents->filter(fn ($e) => $e->isPast())->take(5);

        // SEO OS (Iteration 2): unique title/canonical; noindex when there
        // is nothing to see (empty events page = thin content).
        $hasContent = $upcoming->isNotEmpty() || $past->isNotEmpty();
        $title = ($gallery->title ?: 'Exhibition') . ' — Events & Openings';
        $description = $upcoming->isNotEmpty()
            ? sprintf('Upcoming events for "%s": %s. RSVP online.', $gallery->title, $upcoming->take(3)->map(fn ($e) => $e->title)->implode(', '))
            : sprintf('Events, openings, and artist talks for the 3D exhibition "%s" on %s.', $gallery->title, config('seo.site_name', 'Exospace'));

        $seo = new \App\Support\Seo\SeoData(
            title: \Illuminate\Support\Str::limit($title, 60),
            description: \Illuminate\Support\Str::limit($description, 155),
            canonicalUrl: url('/gallery/' . $gallery->slug . '/events'),
            robots: $hasContent ? null : 'noindex,follow',
            ogTitle: $title,
            ogDescription: \Illuminate\Support\Str::limit($description, 155),
            ogImage: url("/gallery/{$gallery->slug}/og-image"),
            ogImageWidth: 1200,
            ogImageHeight: 630,
        );

        $breadcrumbs = Breadcrumb::trail([
            ['Home', url('/')],
            ['Discover', route('discover')],
            [$gallery->title ?: 'Exhibition', $gallery->public_url],
            ['Events'],
        ]);

        return view('gallery.events', [
            'gallery' => $gallery,
            'upcoming' => $upcoming,
            'past' => $past,
            'seoData' => $seo,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function rsvp(Request $request, string $slug, GalleryScheduleEvent $event): RedirectResponse
    {
        $gallery = Gallery::where('slug', $slug)->where('is_active', true)->firstOrFail();
        if ($event->gallery_id !== $gallery->id) abort(404);

        // ITERATION-3: PIN gate on the write path too — without this, a
        // POST could reserve a seat at a private event without ever
        // entering the exhibition PIN (the GET redirect alone was not
        // enforcement, just UI).
        if ($gallery->hasPinProtection() && ! session("pin_verified_{$gallery->id}")) {
            return redirect()->route('gallery.pin', $gallery->slug);
        }

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
