<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public-facing artist profile pages.
 *
 * Route: GET /artist/{slug}
 *
 * Shows the artist's bio, portrait, social links, and all their works
 * across all public galleries. Each work links to the gallery it's in.
 *
 * This is the discovery surface that powers cross-gallery browsing —
 * a visitor in one gallery can click "More by this artist" and land here.
 */
class ArtistProfileController extends Controller
{
    public function show(string $slug): View
    {
        $artist = Artist::where('slug', $slug)->firstOrFail();

        // Load only images from publicly-viewable galleries.
        // (Task H06 / audit H11) — previously this only checked `is_active`,
        // which leaked images from PIN-protected and scheduled galleries
        // (visitor could browse /artist/{slug} and see works from a
        // gallery whose PIN they didn't know, or that hadn't opened yet).
        // Now uses the same `publiclyViewable` scope as DiscoverController
        // and SitemapController — checks is_active + no pin_hash + within
        // schedule window.
        $images = $artist->images()
            ->with(['gallery.venueTemplate', 'gallery.user'])
            ->whereHas('gallery', function ($q) {
                $q->publiclyViewable();
            })
            ->orderBy('created_at', 'desc')
            ->get();

        // Group by gallery
        $galleries = $images->groupBy('gallery_id')->map(function ($imgs) {
            return [
                'gallery' => $imgs->first()->gallery,
                'images'  => $imgs,
            ];
        })->filter(fn ($g) => $g['gallery'] !== null);

        return view('artists.show', compact('artist', 'galleries'));
    }
}
