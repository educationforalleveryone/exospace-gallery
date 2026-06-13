<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGalleryAccess;
use App\Models\Gallery;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class GalleryController extends Controller
{
    use AuthorizesGalleryAccess;

    // ── Index: show personal OR team galleries ────────────────────────────

    public function index(Request $request): View
    {
        $user   = Auth::user();
        $team   = $this->resolveTeamContext($user, $request->query('team'));

        $galleries = $team
            ? Gallery::with(['images' => fn($q) => $q->orderBy('position_order')->limit(1), 'venueTemplate'])->where('team_id', $team->id)->latest()->paginate(10)
            : Gallery::with(['images' => fn($q) => $q->orderBy('position_order')->limit(1), 'venueTemplate'])->where('user_id', $user->id)->whereNull('team_id')->latest()->paginate(10);

        $userTeams = $user->ownedTeams->merge($user->teams);

        return view('admin.galleries.index', compact('galleries', 'team', 'userTeams'));
    }

    // ── Create ────────────────────────────────────────────────────────────

    public function create(Request $request): View|RedirectResponse
    {
        $user = Auth::user();
        $team = $this->resolveEditableTeam($user, $request->query('team'));

        if ($redirect = $this->checkGalleryLimit($user, $team)) {
            return $redirect;
        }

        $venueTemplates = \App\Models\VenueTemplate::where('is_active', true)->orderBy('sort_order')->get();
        return view('admin.galleries.create', compact('team', 'venueTemplates'));
    }

    // ── Store ─────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $team = $this->resolveEditableTeam($user, $request->input('team_id'));

        if ($redirect = $this->checkGalleryLimit($user, $team)) {
            return $redirect;
        }

        // Strip emoji from title to prevent utf8 column errors
        $request->merge([
            'title' => preg_replace('/[\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{27FF}]|[\x{2B00}-\x{2BFF}]|[\x{FE00}-\x{FE0F}]|[\x{1F300}-\x{1F9FF}]|[\x{1FA00}-\x{1FA9F}]|\x{200D}/u', '', $request->input('title', '')),
        ]);

        $validated = $request->validate($this->galleryValidationRules());

        $planHolder = $team ? $team->owner : $user;

        $audioPath = null;
        if ($request->hasFile('audio') && $planHolder->isPro()) {
            $audioPath = $request->file('audio')->store('audio', 'public');
        }

        $logoPath = null;
        if ($request->hasFile('custom_logo') && $planHolder->plan === 'studio') {
            $logoPath = $request->file('custom_logo')->store('branding', 'public');
        }

        // FIX: treat empty string venue_template_id as null — the form submits "" when
        // no venue card has been clicked, which causes an "exists" validation failure or
        // a DB foreign-key 500 if it slips through.
        $venueTemplateId = !empty($validated['venue_template_id']) ? $validated['venue_template_id'] : null;

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
            'venue_template_id' => $venueTemplateId,
            'audio_path'        => $audioPath,
            'custom_logo_path'  => $logoPath,
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
        $gallery->load('images', 'venueTemplate');
        $venueTemplates = \App\Models\VenueTemplate::where('is_active', true)->orderBy('sort_order')->get();
        return view('admin.galleries.edit', compact('gallery', 'venueTemplates'));
    }

    // ── Update ────────────────────────────────────────────────────────────

    public function update(Request $request, Gallery $gallery): \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $this->authorizeGalleryAccess($gallery);

        // Strip emoji from title to prevent utf8 column errors
        $request->merge([
            'title' => preg_replace('/[\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{27FF}]|[\x{2B00}-\x{2BFF}]|[\x{FE00}-\x{FE0F}]|[\x{1F300}-\x{1F9FF}]|[\x{1FA00}-\x{1FA9F}]|\x{200D}/u', '', $request->input('title', '')),
        ]);

        $validated = $request->validate($this->galleryValidationRules(isUpdate: true));

        $planHolder = $this->galleryPlanHolder($gallery);

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
        } elseif (! empty($validated['gallery_pin'])) {
            $validated['pin_hash'] = Hash::make($validated['gallery_pin']);
        }

        $validated['opens_at']  = $validated['opens_at']  ?: null;
        $validated['closes_at'] = $validated['closes_at'] ?: null;

        // FIX: treat empty string venue_template_id as null
        if (array_key_exists('venue_template_id', $validated)) {
            $validated['venue_template_id'] = !empty($validated['venue_template_id'])
                ? $validated['venue_template_id']
                : null;
        }

        unset($validated['gallery_pin'], $validated['clear_pin'], $validated['audio'], $validated['custom_logo']);

        $gallery->update($validated);

        // Return JSON for AJAX requests (edit page uses fetch), redirect otherwise
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Gallery settings updated!']);
        }
        return back()->with('status', 'Gallery settings updated!');
    }

    // ── Destroy ───────────────────────────────────────────────────────────

    public function destroy(Gallery $gallery): RedirectResponse
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);
        $teamId = $gallery->team_id;
        $gallery->delete();
        return redirect()->route('admin.galleries.index', $teamId ? ['team' => $teamId] : [])
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

        if (! $this->galleryPlanHolder($gallery)->isPro()) {
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

        if ($this->galleryPlanHolder($gallery)->plan !== 'studio') {
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

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Resolve team context from a team ID param, validating membership.
     * Returns null if param is absent or user isn't a member.
     */
    private function resolveTeamContext($user, ?string $teamId): ?Team
    {
        if (! $teamId) {
            $teamId = $user->current_team_id;
        }
        if (! $teamId) return null;

        $team = Team::find($teamId);
        return ($team && $user->belongsToTeam($team)) ? $team : null;
    }

    /**
     * Like resolveTeamContext but also requires edit permission.
     */
    private function resolveEditableTeam($user, ?string $teamId): ?Team
    {
        if (! $teamId) {
            $teamId = $user->current_team_id;
        }
        if (! $teamId) return null;

        $team = Team::find($teamId);
        return ($team && $team->canEdit($user)) ? $team : null;
    }

    /**
     * Check gallery creation limits. Returns a redirect if the limit is hit, null otherwise.
     */
    private function checkGalleryLimit($user, ?Team $team): ?RedirectResponse
    {
        if (! $team) {
            if (! $user->canCreateGallery()) {
                return redirect()->route('admin.galleries.index')->with('upgrade', true);
            }
            return null;
        }

        $owner = $team->owner;
        if (Gallery::where('team_id', $team->id)->count() >= $owner->max_galleries) {
            return redirect()->route('admin.galleries.index', ['team' => $team->id])->with('upgrade', true);
        }

        return null;
    }

    /**
     * Shared validation rules for create and update.
     *
     * FIX: Added 'integer' to venue_template_id rule so that an empty string ""
     * (submitted when no venue card is selected) is cast/rejected cleanly rather
     * than hitting the 'exists' check and producing a confusing 500.
     */
    private function galleryValidationRules(bool $isUpdate = false): array
    {
        $rules = [
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'wall_texture'    => 'required|in:white,concrete,brick,wood',
            'frame_style'     => 'required|in:modern,classic,minimal',
            'lighting_preset' => 'required|in:bright,moody,dramatic',
            'floor_material'  => 'required|in:wood,marble,concrete',
            'room_layout'          => 'required|in:square,corridor,l-shape,rotunda',
            'venue_template_id'    => 'nullable|integer|exists:venue_templates,id',
            'gallery_pin'     => 'nullable|digits:4',
            'opens_at'        => 'nullable|date',
            'closes_at'       => 'nullable|date|after_or_equal:opens_at',
            'audio'           => 'nullable|file|mimes:mp3,wav,m4a|max:10240',
            'custom_logo'     => 'nullable|file|mimes:png,svg,jpg,jpeg|max:2048',
        ];

        if ($isUpdate) {
            $rules['clear_pin'] = 'nullable|boolean';
        }

        return $rules;
    }
}