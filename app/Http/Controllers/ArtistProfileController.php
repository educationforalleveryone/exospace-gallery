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

        // Load only images from publicly-viewable galleries
        $images = $artist->images()
            ->with(['gallery.venueTemplate', 'gallery.user'])
            ->whereHas('gallery', function ($q) {
                $q->where('is_active', true);
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
