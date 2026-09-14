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

class PublicEventController extends Controller
{
    public function __construct(
        private readonly TurnstileService $turnstile,
        private readonly SeoManager $seo,
    ) {}

    public function index(string $slug): View|\Illuminate\Http\RedirectResponse
    {
        $gallery = Gallery::publiclyAccessible()
            ->where('slug', $slug)
            ->with(['scheduleEvents' => function ($q) {
                $q->active()->orderBy('starts_at')->withCount('rsvps'); // eager-load rsvps_count
            }, 'venueTemplate'])
            ->firstOrFail();

        // Closed exhibitions defer to their own closed page.
        if ($gallery->hasClosed()) {
            return redirect()->route('gallery.view', $gallery->slug);
        }

        if ($gallery->hasPinProtection() && ! session("pin_verified_{$gallery->id}")) {
            return redirect()->route('gallery.pin', $gallery->slug);
        }

        $upcoming = $gallery->scheduleEvents->filter(fn ($e) => $e->isUpcoming())->values();
        // The eager load runs oldest-first; history reads newest-first so the
        // most recent events are the ones the limited slice keeps.
        $past = $gallery->scheduleEvents->filter(fn ($e) => $e->isPast())->sortByDesc('starts_at')->take(5)->values();

        $hasContent = $upcoming->isNotEmpty() || $past->isNotEmpty();

        $robots = $hasContent ? null : 'noindex,follow';
        // Verified visitors may see gated schedules, but crawlers always land
        // on the PIN screen — a gated page must never present as indexable.
        if ($gallery->hasPinProtection()) {
            $robots = 'noindex,nofollow';
        }

        $title = ($gallery->title ?: 'Exhibition') . ' — Events & Openings';
        $description = $upcoming->isNotEmpty()
            ? sprintf('Upcoming events for "%s": %s. RSVP online.', $gallery->title, $upcoming->take(3)->map(fn ($e) => $e->title)->implode(', '))
            : sprintf('Events, openings, and artist talks for the 3D exhibition "%s" on %s.', $gallery->title, config('seo.site_name', 'Exospace'));

        $seo = new \App\Support\Seo\SeoData(
            title: \Illuminate\Support\Str::limit($title, 60),
            description: \Illuminate\Support\Str::limit($description, 155),
            canonicalUrl: url('/gallery/' . $gallery->slug . '/events'),
            robots: $robots,
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
        $gallery = Gallery::publiclyAccessible()->where('slug', $slug)->firstOrFail();
        if ($event->gallery_id !== $gallery->id) abort(404);

        if ($gallery->hasClosed()) {
            return redirect()->route('gallery.view', $gallery->slug);
        }

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

        // Verify Turnstile captcha if enabled.
        if (! $this->turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            return back()->withErrors(['captcha' => 'Captcha verification failed. Please refresh and try again.'])->withInput();
        }

        // Enforce capacity
        if ($event->isAtCapacity()) {
            return back()->with('error', 'This event has reached capacity.');
        }

        // Idempotent: unique on (schedule_event_id, email). A concurrent
        // duplicate submit loses the race on the unique index — the row then
        // already exists, which is the same outcome the visitor asked for.
        try {
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
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            //
        }

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
