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
    public function index(): View
    {
        $galleries = Gallery::where('user_id', Auth::id())
            ->latest()
            ->paginate(10);

        return view('admin.galleries.index', compact('galleries'));
    }

    public function create(): View
    {
        if (!Auth::user()->canCreateGallery()) {
            return redirect()->route('admin.galleries.index')
                ->with('upgrade', true);
        }

        return view('admin.galleries.create');
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->canCreateGallery()) {
            return redirect()->route('admin.galleries.index')
                ->with('upgrade', true);
        }

        $validated = $request->validate([
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'wall_texture'    => 'required|in:white,concrete,brick,wood',
            'frame_style'     => 'required|in:modern,classic,minimal',
            'lighting_preset' => 'required|in:bright,moody,dramatic',
            'floor_material'  => 'required|in:wood,marble,concrete',
            'room_layout'     => 'required|in:square,corridor,l-shape,rotunda',  // ← NEW
            'audio'           => 'nullable|file|mimes:mp3,wav,m4a|max:10240',
            'custom_logo'     => 'nullable|file|mimes:png,svg,jpg,jpeg|max:2048',
        ]);

        $audioPath = null;
        if ($request->hasFile('audio') && Auth::user()->isPro()) {
            $audioPath = $request->file('audio')->store('audio', 'public');
        }

        $logoPath = null;
        if ($request->hasFile('custom_logo') && Auth::user()->plan === 'studio') {
            $logoPath = $request->file('custom_logo')->store('branding', 'public');
        }

        $request->user()->galleries()->create([
            'title'            => $validated['title'],
            'description'      => $validated['description'],
            'wall_texture'     => $validated['wall_texture'],
            'frame_style'      => $validated['frame_style'],
            'lighting_preset'  => $validated['lighting_preset'],
            'floor_material'   => $validated['floor_material'],
            'room_layout'      => $validated['room_layout'],   // ← NEW
            'audio_path'       => $audioPath,
            'custom_logo_path' => $logoPath,
        ]);

        return redirect()->route('admin.galleries.index')
            ->with('status', 'Gallery created! You can now upload images.');
    }

    public function show(Gallery $gallery)
    {
        return redirect()->route('admin.galleries.edit', $gallery);
    }

    public function edit(Gallery $gallery): View
    {
        if ($gallery->user_id !== Auth::id()) {
            abort(403);
        }

        $gallery->load('images');

        return view('admin.galleries.edit', compact('gallery'));
    }

    public function update(Request $request, Gallery $gallery): RedirectResponse
    {
        if ($gallery->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'wall_texture'    => 'required|in:white,concrete,brick,wood',
            'frame_style'     => 'required|in:modern,classic,minimal',
            'lighting_preset' => 'required|in:bright,moody,dramatic',
            'floor_material'  => 'required|in:wood,marble,concrete',
            'room_layout'     => 'required|in:square,corridor,l-shape,rotunda',  // ← NEW
            'audio'           => 'nullable|file|mimes:mp3,wav,m4a|max:10240',
            'custom_logo'     => 'nullable|file|mimes:png,svg,jpg,jpeg|max:2048',
        ]);

        if ($request->hasFile('audio') && Auth::user()->isPro()) {
            if ($gallery->audio_path) {
                \Storage::disk('public')->delete($gallery->audio_path);
            }
            $validated['audio_path'] = $request->file('audio')->store('audio', 'public');
        }

        if ($request->hasFile('custom_logo') && Auth::user()->plan === 'studio') {
            if ($gallery->custom_logo_path) {
                \Storage::disk('public')->delete($gallery->custom_logo_path);
            }
            $validated['custom_logo_path'] = $request->file('custom_logo')->store('branding', 'public');
        }

        $gallery->update($validated);

        return back()->with('status', 'Gallery settings updated!');
    }

    public function destroy(Gallery $gallery): RedirectResponse
    {
        if ($gallery->user_id !== Auth::id()) {
            abort(403);
        }

        $gallery->delete();

        return redirect()->route('admin.galleries.index')
            ->with('status', 'Gallery deleted.');
    }

    public function uploadAudio(Request $request, Gallery $gallery)
    {
        if ($gallery->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if (!Auth::user()->isPro()) {
            return response()->json(['success' => false, 'message' => 'Upgrade to Pro to use background music'], 403);
        }

        $request->validate(['audio' => 'required|file|mimes:mp3,wav,m4a|max:10240']);

        try {
            if ($gallery->audio_path) {
                \Storage::disk('public')->delete($gallery->audio_path);
            }

            $audioPath = $request->file('audio')->store('audio', 'public');
            $gallery->update(['audio_path' => $audioPath]);

            return response()->json([
                'success'   => true,
                'message'   => 'Background music uploaded successfully!',
                'audio_url' => asset('storage/' . $audioPath),
                'filename'  => basename($audioPath),
            ]);
        } catch (\Exception $e) {
            \Log::error('Audio upload failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Upload failed. Please try again.'], 500);
        }
    }

    public function uploadLogo(Request $request, Gallery $gallery)
    {
        if ($gallery->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if (Auth::user()->plan !== 'studio') {
            return response()->json(['success' => false, 'message' => 'Upgrade to Studio to use custom branding'], 403);
        }

        $request->validate(['custom_logo' => 'required|file|mimes:png,svg,jpg,jpeg|max:2048']);

        try {
            if ($gallery->custom_logo_path) {
                \Storage::disk('public')->delete($gallery->custom_logo_path);
            }

            $logoPath = $request->file('custom_logo')->store('branding', 'public');
            $gallery->update(['custom_logo_path' => $logoPath]);

            return response()->json([
                'success'  => true,
                'message'  => 'Custom logo uploaded successfully!',
                'logo_url' => asset('storage/' . $logoPath),
                'filename' => basename($logoPath),
            ]);
        } catch (\Exception $e) {
            \Log::error('Logo upload failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Upload failed. Please try again.'], 500);
        }
    }
}