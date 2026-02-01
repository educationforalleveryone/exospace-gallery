<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class GalleryController extends Controller
{
    /**
     * Display a listing of galleries.
     */
    public function index(): View
    {
        $galleries = Gallery::where('user_id', Auth::id())
            ->latest()
            ->paginate(10);

        return view('admin.galleries.index', compact('galleries'));
    }

    /**
     * Show the form for creating a new gallery.
     */
    public function create(): View
    {
        if (!Auth::user()->canCreateGallery()) {
            return redirect()->route('admin.galleries.index')
                ->with('upgrade', true);
        }

        return view('admin.galleries.create');
    }

    /**
     * Store a newly created gallery in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        // ── PAYWALL: Hard limit enforcement ──
        if (!Auth::user()->canCreateGallery()) {
            return redirect()->route('admin.galleries.index')
                ->with('upgrade', true);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'wall_texture' => 'required|in:white,concrete,brick,wood',
            'frame_style' => 'required|in:modern,classic,minimal',
            'lighting_preset' => 'required|in:bright,moody,dramatic',
            'floor_material' => 'required|in:wood,marble,concrete',
        ]);

        // Create the gallery linked to the auth user
        $gallery = $request->user()->galleries()->create($validated);

        // Redirect to index
        return redirect()->route('admin.galleries.index')
            ->with('status', 'Gallery created! You can now upload images.');
    }

    /**
     * Display the specified gallery.
     */
    public function show(Gallery $gallery)
    {
        // Redirect to edit for now
        return redirect()->route('admin.galleries.edit', $gallery);
    }

    /**
     * Show the form for editing the specified gallery.
     */
    public function edit(Gallery $gallery): View
    {
        // Security check
        if ($gallery->user_id !== Auth::id()) {
            abort(403);
        }
        
        // Eager load images
        $gallery->load('images');
        
        return view('admin.galleries.edit', compact('gallery'));
    }

    /**
     * Update the specified gallery in storage.
     */
    public function update(Request $request, Gallery $gallery): RedirectResponse
    {
        if ($gallery->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'wall_texture' => 'required|in:white,concrete,brick,wood',
            'frame_style' => 'required|in:modern,classic,minimal',
            'lighting_preset' => 'required|in:bright,moody,dramatic',
            'floor_material' => 'required|in:wood,marble,concrete',
        ]);

        $gallery->update($validated);

        return back()->with('status', 'Gallery settings updated!');
    }

    /**
     * Remove the specified gallery from storage.
     */
    public function destroy(Gallery $gallery): RedirectResponse
    {
        if ($gallery->user_id !== Auth::id()) {
            abort(403);
        }
        
        // TODO: Cleanup files via ImageProcessingService
        $gallery->delete();
        
        return redirect()->route('admin.galleries.index')
            ->with('status', 'Gallery deleted.');
    }
}