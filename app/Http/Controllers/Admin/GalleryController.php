<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class GalleryController extends Controller
{
    // ── Index: show personal OR team galleries ────────────────────────────

    public function index(Request $request): View
    {
        $user    = Auth::user();
        $team    = null;
        $teamId  = $request->query('team') ?? $user->current_team_id;

        if ($teamId) {
            $team = Team::find($teamId);
            // Validate the user actually belongs to this team
            if ($team && $user->belongsToTeam($team)) {
                $galleries = Gallery::where('team_id', $team->id)
                                    ->latest()->paginate(10);
            } else {
                $team      = null;
                $galleries = Gallery::where('user_id', $user->id)
                                    ->whereNull('team_id')
                                    ->latest()->paginate(10);
            }
        } else {
            $galleries = Gallery::where('user_id', $user->id)
                                ->whereNull('team_id')
                                ->latest()->paginate(10);
        }

        // Pass along the list of teams user belongs to (for switcher)
        $userTeams = $user->ownedTeams->merge($user->teams);

        return view('admin.galleries.index', compact('galleries', 'team', 'userTeams'));
    }

    // ── Create ────────────────────────────────────────────────────────────

    public function create(Request $request): View|RedirectResponse
    {
        $user   = Auth::user();
        $team   = null;
        $teamId = $request->query('team') ?? $user->current_team_id;

        if ($teamId) {
            $team = Team::find($teamId);
            if (! $team || ! $team->canEdit($user)) {
                $team = null;
            }
        }

        // For personal galleries, check the user's own limit
        if (! $team && ! $user->canCreateGallery()) {
            return redirect()->route('admin.galleries.index')->with('upgrade', true);
        }

        // For team galleries, check the team owner's limit
        if ($team) {
            $owner = $team->owner;
            $teamGalleryCount = Gallery::where('team_id', $team->id)->count();
            if ($teamGalleryCount >= $owner->max_galleries) {
                return redirect()->route('admin.galleries.index', ['team' => $team->id])
                                 ->with('upgrade', true);
            }
        }

        return view('admin.galleries.create', compact('team'));
    }

    // ── Store ─────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $user   = Auth::user();
        $team   = null;
        $teamId = $request->input('team_id') ?? $user->current_team_id;

        if ($teamId) {
            $team = Team::find($teamId);
            if (! $team || ! $team->canEdit($user)) {
                $team = null;
            }
        }

        if (! $team && ! $user->canCreateGallery()) {
            return redirect()->route('admin.galleries.index')->with('upgrade', true);
        }

        if ($team) {
            $owner = $team->owner;
            $teamGalleryCount = Gallery::where('team_id', $team->id)->count();
            if ($teamGalleryCount >= $owner->max_galleries) {
                return redirect()->route('admin.galleries.index', ['team' => $team->id])
                                 ->with('upgrade', true);
            }
        }

        $validated = $request->validate([
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'wall_texture'    => 'required|in:white,concrete,brick,wood',
            'frame_style'     => 'required|in:modern,classic,minimal',
            'lighting_preset' => 'required|in:bright,moody,dramatic',
            'floor_material'  => 'required|in:wood,marble,concrete',
            'room_layout'     => 'required|in:square,corridor,l-shape,rotunda',
            'gallery_pin'     => 'nullable|digits:4',
            'opens_at'        => 'nullable|date',
            'closes_at'       => 'nullable|date|after_or_equal:opens_at',
            'audio'           => 'nullable|file|mimes:mp3,wav,m4a|max:10240',
            'custom_logo'     => 'nullable|file|mimes:png,svg,jpg,jpeg|max:2048',
        ]);

        // For pro features, use the plan of the gallery creator (personal) or team owner
        $planHolder = $team ? $team->owner : $user;

        $audioPath = null;
        if ($request->hasFile('audio') && $planHolder->isPro()) {
            $audioPath = $request->file('audio')->store('audio', 'public');
        }

        $logoPath = null;
        if ($request->hasFile('custom_logo') && $planHolder->plan === 'studio') {
            $logoPath = $request->file('custom_logo')->store('branding', 'public');
        }

        Gallery::create([
            'user_id'          => $user->id,
            'team_id'          => $team?->id,
            'title'            => $validated['title'],
            'description'      => $validated['description'],
            'wall_texture'     => $validated['wall_texture'],
            'frame_style'      => $validated['frame_style'],
            'lighting_preset'  => $validated['lighting_preset'],
            'floor_material'   => $validated['floor_material'],
            'room_layout'      => $validated['room_layout'],
            'pin_hash'         => $validated['gallery_pin'] ? Hash::make($validated['gallery_pin']) : null,
            'opens_at'         => $validated['opens_at'] ?? null,
            'closes_at'        => $validated['closes_at'] ?? null,
            'audio_path'       => $audioPath,
            'custom_logo_path' => $logoPath,
        ]);

        $redirectParams = $team ? ['team' => $team->id] : [];
        return redirect()->route('admin.galleries.index', $redirectParams)
                         ->with('status', 'Gallery created! You can now upload images.');
    }

    // ── Show (redirects to edit) ──────────────────────────────────────────

    public function show(Gallery $gallery)
    {
        return redirect()->route('admin.galleries.edit', $gallery);
    }

    // ── Edit ──────────────────────────────────────────────────────────────

    public function edit(Gallery $gallery): View
    {
        $this->authorizeGalleryAccess($gallery);
        $gallery->load('images');
        return view('admin.galleries.edit', compact('gallery'));
    }

    // ── Update ────────────────────────────────────────────────────────────

    public function update(Request $request, Gallery $gallery): RedirectResponse
    {
        $this->authorizeGalleryAccess($gallery);

        $validated = $request->validate([
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'wall_texture'    => 'required|in:white,concrete,brick,wood',
            'frame_style'     => 'required|in:modern,classic,minimal',
            'lighting_preset' => 'required|in:bright,moody,dramatic',
            'floor_material'  => 'required|in:wood,marble,concrete',
            'room_layout'     => 'required|in:square,corridor,l-shape,rotunda',
            'gallery_pin'     => 'nullable|digits:4',
            'clear_pin'       => 'nullable|boolean',
            'opens_at'        => 'nullable|date',
            'closes_at'       => 'nullable|date|after_or_equal:opens_at',
            'audio'           => 'nullable|file|mimes:mp3,wav,m4a|max:10240',
            'custom_logo'     => 'nullable|file|mimes:png,svg,jpg,jpeg|max:2048',
        ]);

        $planHolder = $gallery->team_id
            ? $gallery->team->owner
            : Auth::user();

        if ($request->hasFile('audio') && $planHolder->isPro()) {
            if ($gallery->audio_path) \Storage::disk('public')->delete($gallery->audio_path);
            $validated['audio_path'] = $request->file('audio')->store('audio', 'public');
        }

        if ($request->hasFile('custom_logo') && $planHolder->plan === 'studio') {
            if ($gallery->custom_logo_path) \Storage::disk('public')->delete($gallery->custom_logo_path);
            $validated['custom_logo_path'] = $request->file('custom_logo')->store('branding', 'public');
        }

        if ($request->boolean('clear_pin')) {
            $validated['pin_hash'] = null;
        } elseif (!empty($validated['gallery_pin'])) {
            $validated['pin_hash'] = Hash::make($validated['gallery_pin']);
        }
        unset($validated['gallery_pin'], $validated['clear_pin'], $validated['audio'], $validated['custom_logo']);

        if (empty($validated['opens_at'])) $validated['opens_at'] = null;
        if (empty($validated['closes_at'])) $validated['closes_at'] = null;

        $gallery->update($validated);
        return back()->with('status', 'Gallery settings updated!');
    }

    // ── Destroy ───────────────────────────────────────────────────────────

    public function destroy(Gallery $gallery): RedirectResponse
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);
        $teamId = $gallery->team_id;
        $gallery->delete();
        $redirectParams = $teamId ? ['team' => $teamId] : [];
        return redirect()->route('admin.galleries.index', $redirectParams)
                         ->with('status', 'Gallery deleted.');
    }

    // ── Image reorder ─────────────────────────────────────────────────────

    public function reorderImages(Request $request, Gallery $gallery)
    {
        $this->authorizeGalleryAccess($gallery);

        $request->validate(['order' => 'required|array', 'order.*' => 'integer']);

        foreach ($request->order as $position => $imageId) {
            $gallery->images()->where('id', $imageId)->update(['position_order' => $position + 1]);
        }

        return response()->json(['success' => true]);
    }

    // ── Audio upload ──────────────────────────────────────────────────────

    public function uploadAudio(Request $request, Gallery $gallery)
    {
        $this->authorizeGalleryAccess($gallery);

        $planHolder = $gallery->team_id ? $gallery->team->owner : Auth::user();
        if (! $planHolder->isPro()) {
            return response()->json(['success' => false, 'message' => 'Upgrade to Pro to use background music'], 403);
        }

        $request->validate(['audio' => 'required|file|mimes:mp3,wav,m4a|max:10240']);
        try {
            if ($gallery->audio_path) \Storage::disk('public')->delete($gallery->audio_path);
            $audioPath = $request->file('audio')->store('audio', 'public');
            $gallery->update(['audio_path' => $audioPath]);
            return response()->json(['success' => true, 'message' => 'Background music uploaded successfully!', 'audio_url' => asset('storage/' . $audioPath), 'filename' => basename($audioPath)]);
        } catch (\Exception $e) {
            \Log::error('Audio upload failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Upload failed. Please try again.'], 500);
        }
    }

    // ── Logo upload ───────────────────────────────────────────────────────

    public function uploadLogo(Request $request, Gallery $gallery)
    {
        $this->authorizeGalleryAccess($gallery);

        $planHolder = $gallery->team_id ? $gallery->team->owner : Auth::user();
        if ($planHolder->plan !== 'studio') {
            return response()->json(['success' => false, 'message' => 'Upgrade to Studio to use custom branding'], 403);
        }

        $request->validate(['custom_logo' => 'required|file|mimes:png,svg,jpg,jpeg|max:2048']);
        try {
            if ($gallery->custom_logo_path) \Storage::disk('public')->delete($gallery->custom_logo_path);
            $logoPath = $request->file('custom_logo')->store('branding', 'public');
            $gallery->update(['custom_logo_path' => $logoPath]);
            return response()->json(['success' => true, 'message' => 'Custom logo uploaded successfully!', 'logo_url' => asset('storage/' . $logoPath), 'filename' => basename($logoPath)]);
        } catch (\Exception $e) {
            \Log::error('Logo upload failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Upload failed. Please try again.'], 500);
        }
    }

    // ── Authorization helper ──────────────────────────────────────────────

    /**
     * Gate check for gallery access.
     * - Personal gallery: must be the owner.
     * - Team gallery: must be a team member; if requireEdit=true, must be editor or owner.
     */
    private function authorizeGalleryAccess(Gallery $gallery, bool $requireEdit = false): void
    {
        $user = Auth::user();

        if ($gallery->team_id) {
            $team = $gallery->team;
            if (! $user->belongsToTeam($team)) abort(403);
            if ($requireEdit && ! $team->canEdit($user)) abort(403);
        } else {
            if ($gallery->user_id !== $user->id) abort(403);
        }
    }
}
