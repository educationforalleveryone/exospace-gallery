<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Super-admin: featured exhibitions editor.
 *
 * Curates which galleries appear at the top of /discover.
 * Existing /discover already filters by is_featured — this controller
 * provides the UI to toggle that flag on individual galleries.
 */
class FeaturedExhibitionsController extends Controller
{
    public function index(Request $request): View
    {
        $query = Gallery::with(['venueTemplate', 'user', 'coverImage'])
            ->withCount('images')
            ->where('is_active', true)
            ->has('images', '>=', 1);

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        // Featured first, then by view count
        $query->orderByDesc('is_featured')->orderByDesc('view_count');
        $galleries = $query->paginate(30)->withQueryString();

        return view('super-admin.featured.index', compact('galleries'));
    }

    public function toggle(Gallery $gallery): RedirectResponse
    {
        $gallery->update(['is_featured' => !$gallery->is_featured]);

        \App\Models\AdminAuditLog::record('gallery.feature_toggled', $gallery, [
            'is_featured' => $gallery->is_featured,
        ]);

        $state = $gallery->is_featured ? 'featured' : 'unfeatured';
        return back()->with('status', "\"{$gallery->title}\" is now {$state}.");
    }
}
