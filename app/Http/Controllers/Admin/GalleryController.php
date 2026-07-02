<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGalleryAccess;
use App\Models\Gallery;
use App\Models\Team;
use App\Services\CoolifyDomainManager;
use App\Services\VenueConfigExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class GalleryController extends Controller
{
    use AuthorizesGalleryAccess;

    public function __construct(
        private readonly CoolifyDomainManager $coolify,
        private readonly VenueConfigExporter $venueExporter,
    ) {}

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

        $venueTemplateId = !empty($validated['venue_template_id']) ? $validated['venue_template_id'] : null;

        // Custom domain is Studio-plan only
        $customDomain = null;
        if (!empty($validated['custom_domain']) && $planHolder->plan === 'studio') {
            $customDomain = $this->normaliseCustomDomain($validated['custom_domain']);
            // Uniqueness check
            if (Gallery::where('custom_domain', $customDomain)->exists()) {
                return back()->withInput()->with('error', "The custom domain \"{$customDomain}\" is already in use.");
            }
        }

        try {
            $gallery = Gallery::create([
                'user_id'          => $user->id,
                'team_id'          => $team?->id,
                'title'            => $validated['title'],
                'description'      => $validated['description'] ?? null,
                'wall_texture'     => $validated['wall_texture'],
                'frame_style'      => $validated['frame_style'],
                'lighting_preset'  => $validated['lighting_preset'],
                'floor_material'   => $validated['floor_material'],
                'room_layout'      => $validated['room_layout'],
                'pin_hash'         => !empty($validated['gallery_pin']) ? Hash::make($validated['gallery_pin']) : null,
                'opens_at'         => $validated['opens_at'] ?? null,
                'closes_at'        => $validated['closes_at'] ?? null,
                'venue_template_id' => $venueTemplateId,
                'audio_path'        => $audioPath,
                'custom_logo_path'  => $logoPath,
                'custom_domain'     => $customDomain,
                'visual_overrides'  => $this->parseVisualOverrides($validated['visual_overrides_json'] ?? null),
            ]);
        } catch (\Throwable $e) {
            \Log::error('Gallery::create failed', [
                'message'  => $e->getMessage(),
                'title'    => $validated['title'] ?? null,
                'user_id'  => $user->id,
                'venue_id' => $venueTemplateId,
                'layout'   => $validated['room_layout'] ?? null,
            ]);
            return back()
                ->withInput()
                ->with('error', 'Could not create gallery: ' . $e->getMessage());
        }

        // If a custom domain was set, register it in Coolify so Traefik
        // routes it + Let's Encrypt provisions a cert. Failures are logged
        // but do NOT fail the gallery creation — the user can retry the
        // domain setup separately.
        $redirectParams = $team ? ['team' => $team->id] : [];

        if ($customDomain) {
            $result = $this->coolify->addDomain($customDomain);
            if (!$result['success']) {
                \Log::warning('Coolify domain registration deferred.', [
                    'gallery_id' => $gallery->id,
                    'domain'     => $customDomain,
                    'reason'     => $result['message'],
                ]);
                // Surface a soft warning to the user via session flash
                return redirect()->route('admin.galleries.index', $redirectParams)
                    ->with('status', 'Gallery created! You can now upload images.')
                    ->with('warning', "Custom domain could not be auto-configured in Coolify: {$result['message']} DNS + SSL setup will need to be done manually.");
            }
        }

        return redirect()->route('admin.galleries.index', $redirectParams)
                         ->with('status', 'Gallery created! You can now upload images.');
    }

    // ── Duplicate (clone) ─────────────────────────────────────────────────
    //
    // Creates a new gallery that copies all settings from an existing one,
    // including image files (copied on disk so the clone is independent).
    // The clone's title gets " (Copy)" appended; its slug is auto-generated.

    public function duplicate(Gallery $gallery): RedirectResponse
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);

        $user = Auth::user();
        $team = $gallery->team;

        // Plan limit check
        if ($redirect = $this->checkGalleryLimit($user, $team)) {
            return $redirect;
        }

        // Create the clone
        $clone = $gallery->replicate([
            'id', 'slug', 'view_count', 'pin_hash', 'opens_at', 'closes_at',
            'custom_domain', // custom domains are unique — never copy
            'created_at', 'updated_at',
        ]);

        $clone->title       = $gallery->title . ' (Copy)';
        $clone->slug        = null; // boot() will generate a new one
        $clone->view_count  = 0;
        $clone->is_active   = true;

        // Copy audio + logo files on disk so the clone is independent
        if ($gallery->audio_path) {
            $newPath = $this->copyFile($gallery->audio_path, 'audio');
            if ($newPath) $clone->audio_path = $newPath;
        }
        if ($gallery->custom_logo_path) {
            $newPath = $this->copyFile($gallery->custom_logo_path, 'branding');
            if ($newPath) $clone->custom_logo_path = $newPath;
        }

        $clone->save();

        // Copy all images — duplicate files on disk + create new GalleryImage rows
        foreach ($gallery->images()->orderBy('position_order')->get() as $image) {
            $newImagePath = $this->copyFile($image->path, 'gallery-images');
            if (!$newImagePath) {
                \Log::warning("Duplicate: failed to copy image {$image->path}");
                continue;
            }
            \App\Models\GalleryImage::create([
                'gallery_id'     => $clone->id,
                'filename'       => $image->filename,
                'original_name'  => $image->original_name,
                'path'           => $newImagePath,
                'mime_type'      => $image->mime_type,
                'size'           => $image->size,
                'width'          => $image->width,
                'height'         => $image->height,
                'orientation'    => $image->orientation,
                'position_order' => $image->position_order,
                'wall_position'  => $image->wall_position,
                'title'          => $image->title,
                'description'    => $image->description,
            ]);
        }

        $redirectParams = $team ? ['team' => $team->id] : [];
        return redirect()
            ->route('admin.galleries.index', $redirectParams)
            ->with('status', "Gallery duplicated as \"{$clone->title}\".");
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

    // ── Live Preview iframe target ────────────────────────────────────────
    //
    // Renders a stripped-down version of the public gallery view:
    //   - no entrance curtain (auto-enters)
    //   - no view_count increment (preview is not a "view")
    //   - no PIN gate (the curator owns the gallery)
    //   - no time-gate (the curator may preview before opens_at)
    //
    // Accepts an optional `?override=<base64-json>` query param so the
    // iframe can be reloaded with un-saved slider tweaks baked in. The
    // override is merged on top of the gallery's stored visual_overrides
    // via VenueConfigExporter::forGalleryPreview().

    public function preview(Request $request, Gallery $gallery): View
    {
        $this->authorizeGalleryAccess($gallery);
        $gallery->load(['images.artist', 'user', 'venueTemplate']);

        $runtimeOverrides = [];
        if ($request->filled('override')) {
            $decoded = base64_decode(strtr($request->input('override'), '-_', '+/'), true);
            if ($decoded !== false) {
                $parsed = json_decode($decoded, true);
                if (is_array($parsed)) {
                    $runtimeOverrides = $parsed;
                }
            }
        }

        $venueConfig = $gallery->venueTemplate
            ? $this->venueExporter->forGalleryPreview($gallery, $runtimeOverrides)
            : null;

        $galleryData = $this->buildGalleryData($gallery, $venueConfig, isPreview: true);

        // Preview flag tells the blade to: skip curtain, hide newsletter form,
        // hide share buttons, and load the PreviewClient listener.
        $galleryData['isPreview'] = true;

        return view('admin.galleries.preview', compact('gallery', 'galleryData'));
    }

    // ── Update ────────────────────────────────────────────────────────────

    public function update(Request $request, Gallery $gallery): \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $this->authorizeGalleryAccess($gallery);

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

        // NEW (Round 4) — Branded entrance curtain (Studio only)
        if ($planHolder->plan === 'studio') {
            if ($request->hasFile('curtain_logo')) {
                if ($gallery->curtain_logo_path) \Storage::disk('public')->delete($gallery->curtain_logo_path);
                $validated['curtain_logo_path'] = $request->file('curtain_logo')->store('branding', 'public');
            } elseif ($request->boolean('clear_curtain_logo') && $gallery->curtain_logo_path) {
                \Storage::disk('public')->delete($gallery->curtain_logo_path);
                $validated['curtain_logo_path'] = null;
            }

            if ($request->boolean('clear_curtain_bg')) {
                $validated['curtain_bg_color'] = null;
            } elseif (!empty($validated['curtain_bg_color'])) {
                // Validate hex color
                $bg = $validated['curtain_bg_color'];
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $bg)) {
                    $validated['curtain_bg_color'] = null;
                }
            }
        }

        if ($request->boolean('clear_pin')) {
            $validated['pin_hash'] = null;
        } elseif (! empty($validated['gallery_pin'])) {
            $validated['pin_hash'] = Hash::make($validated['gallery_pin']);
        }

        $validated['opens_at']  = $validated['opens_at']  ?: null;
        $validated['closes_at'] = $validated['closes_at'] ?: null;

        if (array_key_exists('venue_template_id', $validated)) {
            $validated['venue_template_id'] = !empty($validated['venue_template_id'])
                ? $validated['venue_template_id']
                : null;
        }

        // NEW (Live Preview) — parse the visual_overrides JSON string coming
        // from the edit page's hidden input. null/empty clears overrides.
        $validated['visual_overrides'] = $this->parseVisualOverrides(
            $validated['visual_overrides_json'] ?? null
        );

        // Custom domain — Studio-plan only, must be unique across galleries
        if (array_key_exists('custom_domain', $validated)) {
            $cd = $validated['custom_domain'];
            if (!empty($cd) && $planHolder->plan === 'studio') {
                $cd = $this->normaliseCustomDomain($cd);
                $exists = Gallery::where('custom_domain', $cd)
                    ->where('id', '!=', $gallery->id)
                    ->exists();
                if ($exists) {
                    return back()->withInput()
                        ->with('error', "The custom domain \"{$cd}\" is already in use.");
                }
                $validated['custom_domain'] = $cd;
                // Clear the lookup cache for the new domain
                \Illuminate\Support\Facades\Cache::forget("custom_domain:{$cd}");

                // Register with Coolify if it's a new domain (different from current)
                if ($cd !== $gallery->getOriginal('custom_domain')) {
                    $coolifyResult = $this->coolify->addDomain($cd);
                    if (!$coolifyResult['success']) {
                        \Log::warning('Coolify domain registration deferred on update.', [
                            'gallery_id' => $gallery->id,
                            'domain'     => $cd,
                            'reason'     => $coolifyResult['message'],
                        ]);
                        // Don't fail the save — but warn the user
                        session()->flash('warning', "Gallery saved, but Coolify could not auto-configure the custom domain: {$coolifyResult['message']}");
                    }
                }
            } elseif (empty($cd)) {
                // Domain was cleared — remove from Coolify if it was set
                if ($gallery->custom_domain) {
                    $oldDomain = $gallery->custom_domain;
                    $this->coolify->removeDomain($oldDomain);
                    \Illuminate\Support\Facades\Cache::forget("custom_domain:{$oldDomain}");
                }
                $validated['custom_domain'] = null;
            } else {
                // Non-Studio plan trying to set a custom domain — block silently
                unset($validated['custom_domain']);
            }
        }

        unset($validated['gallery_pin'], $validated['clear_pin'], $validated['audio'], $validated['custom_logo'],
              $validated['curtain_logo'], $validated['clear_curtain_logo'], $validated['clear_curtain_bg'],
              $validated['curtain_bg_color_text'], $validated['visual_overrides_json']);

        $gallery->update($validated);

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

        // Clean up custom domain cache + remove from Coolify if set
        if ($gallery->custom_domain) {
            $oldDomain = $gallery->custom_domain;
            \Illuminate\Support\Facades\Cache::forget("custom_domain:{$oldDomain}");
            $this->coolify->removeDomain($oldDomain);
        }

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

    private function resolveTeamContext($user, ?string $teamId): ?Team
    {
        if (! $teamId) {
            $teamId = $user->current_team_id;
        }
        if (! $teamId) return null;

        $team = Team::find($teamId);
        return ($team && $user->belongsToTeam($team)) ? $team : null;
    }

    private function resolveEditableTeam($user, ?string $teamId): ?Team
    {
        if (! $teamId) {
            $teamId = $user->current_team_id;
        }
        if (! $teamId) return null;

        $team = Team::find($teamId);
        return ($team && $team->canEdit($user)) ? $team : null;
    }

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
     * Normalise a custom domain: lowercase, strip scheme/path/port.
     * The Gallery model's saving() event also does this, but doing it
     * here lets us do the uniqueness check on the normalised value.
     */
    private function normaliseCustomDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];
        return $domain;
    }

    /**
     * Copy a file within the public disk and return the new path,
     * or null on failure.
     */
    private function copyFile(?string $path, string $folder): ?string
    {
        if (!$path) return null;
        try {
            $disk = Storage::disk('public');
            if (!$disk->exists($path)) return null;
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $newName = $folder . '/' . \Str::random(40) . ($ext ? '.' . $ext : '');
            $disk->copy($path, $newName);
            return $newName;
        } catch (\Throwable $e) {
            \Log::warning("copyFile failed for {$path}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Parse the visual_overrides JSON string from the edit form's hidden
     * input. Returns a structured array with the three buckets
     * (visual_config, material_config, post_fx), or null if the input is
     * empty/invalid — which clears the column so the venue defaults take over.
     *
     * Validation lives in galleryValidationRules() — this method only
     * decodes + sanitises.
     */
    private function parseVisualOverrides(?string $json): ?array
    {
        if (!$json || trim($json) === '') return null;
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return null;

        // Whitelist the three buckets so a malicious payload can't inject
        // arbitrary top-level keys that would later be merged into the
        // venue config exported to the browser.
        $clean = [
            'visual_config'   => is_array($decoded['visual_config']   ?? null) ? $decoded['visual_config']   : [],
            'material_config' => is_array($decoded['material_config'] ?? null) ? $decoded['material_config'] : [],
            'post_fx'         => is_array($decoded['post_fx']         ?? null) ? $decoded['post_fx']         : [],
        ];

        // Strip empty buckets entirely so the column stays small + so
        // hasVisualOverrides() returns false when the curator hits "Reset all".
        $clean = array_filter($clean, fn ($bucket) => !empty($bucket));
        return empty($clean) ? null : $clean;
    }

    /**
     * Build the gallery data array consumed by the 3D viewer.
     * Extracted from GalleryViewController::show() so the preview route
     * and the public route produce identical data shapes (the preview
     * just skips the time-gate, PIN, and view-count bump).
     */
    private function buildGalleryData(Gallery $gallery, ?array $venueConfig, bool $isPreview = false): array
    {
        return [
            'id'          => $gallery->id,
            'title'       => $gallery->title,
            'description' => $gallery->description,
            'wall_texture'    => $gallery->wall_texture,
            'floor_material'  => $gallery->floor_material,
            'frame_style'     => $gallery->frame_style,
            'lighting_preset' => $gallery->lighting_preset,
            'room_layout'     => $gallery->room_layout ?? 'square',
            'venue_slug'      => $gallery->venueTemplate?->slug ?? 'white-cube',
            'venueConfig'     => $venueConfig,
            'images' => $gallery->images->map(fn($img) => [
                'id'             => $img->id,
                'url'            => asset($img->path),
                'width'          => $img->width,
                'height'         => $img->height,
                'aspectRatio'    => $img->width / max($img->height, 1),
                'orientation'    => $img->orientation,
                'title'          => $img->title ?? $img->original_name,
                'description'    => $img->description,
                'artist'         => $img->artist ? [
                    'id'     => $img->artist->id,
                    'name'   => $img->artist->name,
                    'slug'   => $img->artist->slug,
                    'url'    => route('artist.profile', $img->artist->slug),
                ] : null,
                'price'          => $img->price ? (float) $img->price : null,
                'currency'       => $img->currency,
                'formattedPrice' => $img->formattedPrice(),
                'forSale'        => (bool) $img->for_sale,
                'medium'         => $img->medium,
                'year'           => $img->year,
                'dimensions'     => $img->dimensions,
                'edition'        => $img->formattedEdition(),
                'externalUrl'    => $img->external_url,
            ])->values(),
            'imageCount'     => $gallery->images->count(),
            'audioUrl'       => $gallery->audio_path ? asset('storage/' . $gallery->audio_path) : null,
            'userPlan'       => $gallery->user->plan ?? 'free',
            'customLogoUrl'  => ($gallery->custom_logo_path && $gallery->user->plan === 'studio')
                                    ? asset('storage/' . $gallery->custom_logo_path)
                                    : null,
            'curtainLogoUrl' => ($gallery->curtain_logo_path && $gallery->user->plan === 'studio')
                                    ? asset('storage/' . $gallery->curtain_logo_path)
                                    : null,
            'curtainBgColor' => ($gallery->curtain_bg_color && $gallery->user->plan === 'studio')
                                    ? $gallery->curtain_bg_color
                                    : null,
            'newsletterUrl'  => $isPreview ? null : route('gallery.newsletter', $gallery->slug),
            'eventsUrl'      => $isPreview ? null : route('gallery.events.index', $gallery->slug),
            'hasUpcomingEvents' => $isPreview ? false : $gallery->scheduleEvents()->active()->upcoming()->exists(),
        ];
    }

    private function galleryValidationRules(bool $isUpdate = false): array
    {
        $rules = [
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            // NOTE: validation is intentionally a whitelist. If you want to
            // allow custom material slugs (e.g. from your own 3D model
            // pipeline), broaden these to 'string|max:50' and add a
            // registration step that drops the matching texture folder
            // under public/assets/textures/<surface>/<material>/.
            'wall_texture'    => 'required|in:white,concrete,brick,wood,plaster,marble,velvet',
            'frame_style'     => 'required|in:modern,classic,minimal,gold,silver,bronze,black',
            'lighting_preset' => 'required|in:bright,moody,dramatic',
            'floor_material'  => 'required|in:wood,marble,concrete,terrazzo,grass,sand',
            'room_layout'     => 'required|in:square,corridor,l-shape,rotunda',
            // Only active + published venues are selectable. Drafts and
            // disabled templates would otherwise be injectable via direct POST.
            'venue_template_id' => ['nullable', 'integer',
                \Illuminate\Validation\Rule::exists('venue_templates', 'id')
                    ->where(fn ($q) => $q->where('is_active', true)->where('is_draft', false)),
            ],
            'gallery_pin'     => 'nullable|digits:4',
            'opens_at'        => 'nullable|date',
            'closes_at'       => 'nullable|date|after_or_equal:opens_at',
            'audio'           => 'nullable|file|mimes:mp3,wav,m4a|max:10240',
            'custom_logo'     => 'nullable|file|mimes:png,svg,jpg,jpeg|max:2048',
            // NEW: custom domain — Studio plan only, validated for shape here.
            // Plan-tier enforcement happens in the controller.
            'custom_domain'   => ['nullable', 'string', 'max:255', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i'],
            // NEW (Round 4) — Branded entrance curtain (Studio only)
            'curtain_logo'        => 'nullable|file|mimes:png,jpeg,svg,webp|max:2048',
            'curtain_bg_color'    => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'curtain_bg_color_text' => 'nullable|string|max:20',
            // NEW (Live Preview) — JSON string from the hidden input. The
            // controller decodes + sanitises via parseVisualOverrides().
            // The regex is a coarse shape check; the controller does the
            // structural validation.
            'visual_overrides_json' => ['nullable', 'string', 'max:16000', 'regex:/^\s*(\{.*\}|\[\])?\s*$/s'],
        ];

        if ($isUpdate) {
            $rules['clear_pin']          = 'nullable|boolean';
            $rules['clear_curtain_logo'] = 'nullable|boolean';
            $rules['clear_curtain_bg']   = 'nullable|boolean';
        }

        return $rules;
    }
}
