<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\VenueTemplate;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public directory of featured / open exhibitions.
 *
 * Route: GET /discover
 *
 * Shows galleries that are:
 *   - is_active = true
 *   - Not PIN-protected (publicly viewable)
 *   - Currently open (within their schedule window, or unscheduled)
 *   - Have at least one image (no empty exhibitions in the directory)
 *
 * Supports sorting by: featured (default), views, newest, recently updated.
 * Supports filtering by venue_template.
 */
class DiscoverController extends Controller
{
    public function index(Request $request): View
    {
        $sort = $request->string('sort', 'featured')->toString();
        $venueId = $request->string('venue');

        $query = Gallery::publiclyViewable()
            ->with(['coverImage', 'venueTemplate', 'user'])
            ->has('images', '>=', 1)
            ->whereDoesntHave('user', fn($q) => $q->whereNotNull('banned_at'));

        // Filter by venue
        if ($venueId) {
            $query->where('venue_template_id', $venueId);
        }

        // Sort
        $query->when($sort === 'views', fn($q) => $q->orderByDesc('view_count'))
              ->when($sort === 'newest', fn($q) => $q->orderByDesc('created_at'))
              ->when($sort === 'updated', fn($q) => $q->orderByDesc('updated_at'))
              ->unless(in_array($sort, ['views', 'newest', 'updated']), function ($q) {
                  // Default: featured galleries first (Round 4 — is_featured column
                  // on galleries, controlled via super-admin /master-control/featured),
                  // then by view_count.
                  return $q->orderByDesc('is_featured')
                           ->orderByDesc('view_count');
              });

        $galleries = $query->paginate(24)->withQueryString();

        // Venues for the filter dropdown
        $venues = VenueTemplate::active()
            ->published()
            ->orderBy('sort_order')
            ->pluck('name', 'id');

        return view('discover.index', compact('galleries', 'venues', 'sort', 'venueId'));
    }
}
