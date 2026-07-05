<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGalleryAccess;
use App\Models\Gallery;
use App\Models\GalleryScheduleEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Admin CRUD for gallery calendar events (opening receptions, artist talks).
 *
 * Curators manage their gallery's event calendar from /admin/galleries/{id}/events.
 * Visitors RSVP via the public GalleryEventController.
 */
class GalleryEventController extends Controller
{
    use AuthorizesGalleryAccess;

    public function index(Gallery $gallery): View
    {
        $this->authorizeGalleryAccess($gallery);
        // PERF-15: Use withCount('rsvps') instead of loading full
        // rsvp rows. The index page only needs the count (for the
        // "X / Y RSVPs" display), not the full rsvp records. The
        // RSVPs admin page (rsvps() method below) loads full rsvp
        // rows when needed.
        $gallery->load(['scheduleEvents' => fn($q) => $q->withCount('rsvps')]);

        $upcoming = $gallery->scheduleEvents()->upcoming()->withCount('rsvps')->get();
        $past = $gallery->scheduleEvents()->past()->limit(20)->withCount('rsvps')->get();

        return view('admin.galleries.events.index', compact('gallery', 'upcoming', 'past'));
    }

    public function create(Gallery $gallery): View
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);
        $event = new GalleryScheduleEvent([
            'type' => 'opening',
            'timezone' => 'UTC',
            'capacity' => null,
        ]);
        $types = GalleryScheduleEvent::TYPES;

        return view('admin.galleries.events.create', compact('gallery', 'event', 'types'));
    }

    public function store(Request $request, Gallery $gallery): RedirectResponse
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);

        $validated = $request->validate([
            'title'         => ['required', 'string', 'max:200'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'type'          => ['required', 'string', 'in:' . implode(',', array_keys(GalleryScheduleEvent::TYPES))],
            'starts_at'     => ['required', 'date'],
            'ends_at'       => ['nullable', 'date', 'after:starts_at'],
            'timezone'      => ['nullable', 'string', 'max:50'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_url'  => ['nullable', 'string', 'max:500', 'url'],
            'capacity'      => ['nullable', 'integer', 'min:1'],
            'is_active'     => ['boolean'],
        ]);

        $validated['gallery_id'] = $gallery->id;
        $validated['is_active'] = $request->boolean('is_active', true);

        $event = GalleryScheduleEvent::create($validated);

        return redirect()
            ->route('admin.galleries.events.index', $gallery)
            ->with('status', "Event \"{$event->title}\" created.");
    }

    public function edit(Gallery $gallery, GalleryScheduleEvent $event): View
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);
        if ($event->gallery_id !== $gallery->id) abort(404);

        $types = GalleryScheduleEvent::TYPES;
        return view('admin.galleries.events.edit', compact('gallery', 'event', 'types'));
    }

    public function update(Request $request, Gallery $gallery, GalleryScheduleEvent $event): RedirectResponse
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);
        if ($event->gallery_id !== $gallery->id) abort(404);

        $validated = $request->validate([
            'title'         => ['required', 'string', 'max:200'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'type'          => ['required', 'string', 'in:' . implode(',', array_keys(GalleryScheduleEvent::TYPES))],
            'starts_at'     => ['required', 'date'],
            'ends_at'       => ['nullable', 'date', 'after:starts_at'],
            'timezone'      => ['nullable', 'string', 'max:50'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_url'  => ['nullable', 'string', 'max:500', 'url'],
            'capacity'      => ['nullable', 'integer', 'min:1'],
            'is_active'     => ['boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);
        $event->update($validated);

        return redirect()
            ->route('admin.galleries.events.index', $gallery)
            ->with('status', "Event \"{$event->title}\" updated.");
    }

    public function destroy(Gallery $gallery, GalleryScheduleEvent $event): RedirectResponse
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);
        if ($event->gallery_id !== $gallery->id) abort(404);

        $title = $event->title;
        $event->delete();

        return redirect()
            ->route('admin.galleries.events.index', $gallery)
            ->with('status', "Event \"{$title}\" deleted.");
    }

    /**
     * View RSVP list for an event.
     */
    public function rsvps(Gallery $gallery, GalleryScheduleEvent $event): View
    {
        $this->authorizeGalleryAccess($gallery);
        if ($event->gallery_id !== $gallery->id) abort(404);

        $rsvps = $event->rsvps()->latest()->get();

        return view('admin.galleries.events.rsvps', compact('gallery', 'event', 'rsvps'));
    }
}
