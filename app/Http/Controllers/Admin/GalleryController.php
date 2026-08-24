<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGalleryAccess;
use App\Models\AdminAuditLog;
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

        // (Task H64) — send "first gallery created" email if this is the
        // user's first personal gallery. Part of the activation sequence.
        $personalGalleryCount = Gallery::where('user_id', $user->id)
            ->whereNull('team_id')
            ->count();
        if ($personalGalleryCount === 1) {
            try {
                \Illuminate\Support\Facades\Mail::to($user->email)
                    ->send(new \App\Mail\FirstGalleryCreatedEmail($user, $gallery));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('FirstGalleryCreatedEmail send failed', [
                    'user_id'    => $user->id,
                    'gallery_id' => $gallery->id,
                    'error'      => $e->getMessage(),
                ]);
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
        // (Task H59 / audit S10) — also copy curtain logo
        if ($gallery->curtain_logo_path) {
            $newPath = $this->copyFile($gallery->curtain_logo_path, 'branding');
            if ($newPath) $clone->curtain_logo_path = $newPath;
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
                // (Task H59 / audit S10) — preserve artist attribution +
                // artwork metadata that was previously lost on duplicate.
                'artist_id'      => $image->artist_id,
                'price'          => $image->price,
                'currency'       => $image->currency,
                'for_sale'       => $image->for_sale,
                'medium'         => $image->medium,
                'year'           => $image->year,
                'dimensions'     => $image->dimensions,
                'edition_size'   => $image->edition_size,
                'edition_number' => $image->edition_number,
                'external_url'   => $image->external_url,
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
        // PERF-A1 (3D audit F1): 'images.media' eager-loaded so the new
        // per-artwork texture variant URLs (see buildGalleryData) read the
        // memoized Spatie media relation instead of issuing one query per
        // image. Matches the public GalleryViewController::show eager-load.
        $gallery->load(['images.artist', 'images.media', 'user', 'venueTemplate']);

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
    //
    // P1-9 FIX (audit): Previously a 162-line method handling 8 concerns.
    // Now a thin orchestrator that delegates to well-named private methods:
    //   - handleFileUploads()      — audio, custom_logo, curtain_logo
    //   - handlePinAndSchedule()   — PIN set/clear, date normalization
    //   - handleVenueTemplate()    — empty string to null
    //   - handleCustomDomain()     — uniqueness check, Coolify, cache, tokens
    //   - applyPostUpdateGuardedFields() — verification tokens after save
    //
    // No behavior change — just extraction for readability + testability.

    public function update(Request $request, Gallery $gallery): \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $this->authorizeGalleryAccess($gallery);

        // Strip emoji from title (Task H22)
        $request->merge([
            'title' => preg_replace('/[\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{27FF}]|[\x{2B00}-\x{2BFF}]|[\x{FE00}-\x{FE0F}]|[\x{1F300}-\x{1F9FF}]|[\x{1FA00}-\x{1FA9F}]|\x{200D}/u', '', $request->input('title', '')),
        ]);

        $validated = $request->validate($this->galleryValidationRules(isUpdate: true));

        $planHolder = $this->galleryPlanHolder($gallery);

        // Delegate to extracted helpers
        $this->handleFileUploads($request, $gallery, $planHolder, $validated);
        $this->handlePinAndSchedule($request, $validated);
        $this->handleVenueTemplate($validated);
        $validated['visual_overrides'] = $this->parseVisualOverrides($validated['visual_overrides_json'] ?? null);

        // Custom domain handling may return early on uniqueness conflict
        $domainResult = $this->handleCustomDomain($request, $gallery, $planHolder, $validated);
        if ($domainResult !== null) {
            return $domainResult; // Redirect back with error
        }

        // SEO OS (Iteration 6): persist curator SEO overrides into the
        // gallery's seo_profile (creates on demand).
        if (array_key_exists('seo_title', $validated) || array_key_exists('seo_description', $validated)) {
            $profile = $gallery->seoProfileOrCreate();
            $profile->fill([
                'title_override'       => $validated['seo_title'] ?? null,
                'description_override' => $validated['seo_description'] ?? null,
                'updated_by'           => $request->user()->id,
            ])->save();
            unset($validated['seo_title'], $validated['seo_description']);
        }

        // Remove non-fillable keys before update
        unset($validated['gallery_pin'], $validated['clear_pin'], $validated['audio'], $validated['custom_logo'],
              $validated['curtain_logo'], $validated['clear_curtain_logo'], $validated['clear_curtain_bg'],
              $validated['curtain_bg_color_text'], $validated['visual_overrides_json']);

        $gallery->update($validated);

        // Post-update: set guarded custom-domain verification fields
        $this->applyPostUpdateGuardedFields($request, $gallery);

        // P3-16: Bulk-invalidate all caches tagged with this gallery's ID.
        // Gallery title/description changes affect: analytics displays,
        // OG image, sitemap, custom-domain gallery cache. Without bulk
        // invalidation, we'd need to track each cache key individually —
        // error-prone and incomplete. CacheTagService handles both Redis
        // native tags (production) and key-tracking fallback (dev/CI).
        $cacheTags = app(\App\Services\CacheTagService::class);
        $cacheTags->invalidateTags([
            "analytics:gallery:{$gallery->id}",
            "gallery:{$gallery->id}",
            'og',
            'sitemap',
        ]);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Gallery settings updated!']);
        }
        return back()->with('status', 'Gallery settings updated!');
    }

    /**
     * P1-9: Handle audio, custom_logo, and curtain_logo file uploads + clears.
     */
    private function handleFileUploads(Request $request, Gallery $gallery, $planHolder, array &$validated): void
    {
        // Audio (Pro+)
        if ($request->hasFile('audio') && $planHolder->isPro()) {
            if ($gallery->audio_path) \Storage::disk('public')->delete($gallery->audio_path);
            $validated['audio_path'] = $request->file('audio')->store('audio', 'public');
        }

        // Custom logo (Studio only)
        if ($request->hasFile('custom_logo') && $planHolder->plan === 'studio') {
            if ($gallery->custom_logo_path) \Storage::disk('public')->delete($gallery->custom_logo_path);
            $validated['custom_logo_path'] = $request->file('custom_logo')->store('branding', 'public');
        }

        // Curtain logo (Studio only) — upload or clear
        if ($planHolder->plan === 'studio') {
            if ($request->hasFile('curtain_logo')) {
                if ($gallery->curtain_logo_path) \Storage::disk('public')->delete($gallery->curtain_logo_path);
                $validated['curtain_logo_path'] = $request->file('curtain_logo')->store('branding', 'public');
            } elseif ($request->boolean('clear_curtain_logo') && $gallery->curtain_logo_path) {
                \Storage::disk('public')->delete($gallery->curtain_logo_path);
                $validated['curtain_logo_path'] = null;
            }

            // Curtain background color — clear or validate hex
            if ($request->boolean('clear_curtain_bg')) {
                $validated['curtain_bg_color'] = null;
            } elseif (!empty($validated['curtain_bg_color'])) {
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $validated['curtain_bg_color'])) {
                    $validated['curtain_bg_color'] = null;
                }
            }
        }
    }

    /**
     * P1-9: Handle PIN set/clear and schedule date normalization.
     */
    private function handlePinAndSchedule(Request $request, array &$validated): void
    {
        if ($request->boolean('clear_pin')) {
            $validated['pin_hash'] = null;
        } elseif (! empty($validated['gallery_pin'])) {
            $validated['pin_hash'] = Hash::make($validated['gallery_pin']);
        }

        $validated['opens_at']  = $validated['opens_at']  ?: null;
        $validated['closes_at'] = $validated['closes_at'] ?: null;
    }

    /**
     * P1-9: Normalize venue_template_id — empty string to null.
     */
    private function handleVenueTemplate(array &$validated): void
    {
        if (array_key_exists('venue_template_id', $validated)) {
            $validated['venue_template_id'] = !empty($validated['venue_template_id'])
                ? $validated['venue_template_id']
                : null;
        }
    }

    /**
     * P1-9: Handle custom domain — uniqueness check, Coolify registration/removal,
     * cache invalidation, and verification token management.
     *
     * Returns a RedirectResponse if the domain is already in use (early return
     * from the caller). Returns null on success.
     */
    private function handleCustomDomain(Request $request, Gallery $gallery, $planHolder, array &$validated): ?\Illuminate\Http\RedirectResponse
    {
        if (! array_key_exists('custom_domain', $validated)) {
            return null;
        }

        $cd = $validated['custom_domain'];

        if (!empty($cd) && $planHolder->plan === 'studio') {
            // Studio user setting a new domain
            $cd = $this->normaliseCustomDomain($cd);
            $exists = Gallery::where('custom_domain', $cd)
                ->where('id', '!=', $gallery->id)
                ->exists();
            if ($exists) {
                return back()->withInput()
                    ->with('error', "The custom domain \"{$cd}\" is already in use.");
            }

            $oldDomain = $gallery->getOriginal('custom_domain');
            $domainChanged = $cd !== $oldDomain;

            $validated['custom_domain'] = $cd;

            \Illuminate\Support\Facades\Cache::forget("custom_domain:{$cd}");
            if ($domainChanged && $oldDomain) {
                \Illuminate\Support\Facades\Cache::forget("custom_domain:{$oldDomain}");
            }

            // PERF-16: Invalidate the eager-loaded gallery-object cache so
            // the next custom-domain request picks up the new custom_domain /
            // custom_logo_path / audio_path / venue_template_id values rather
            // than serving the stale cached copy for up to 5 minutes.
            \Illuminate\Support\Facades\Cache::forget("custom_domain_gallery:{$gallery->id}");

            if ($domainChanged) {
                $request->attributes->set('_pending_domain_token', \Illuminate\Support\Str::random(32));

                if ($oldDomain && $gallery->custom_domain_verified_at) {
                    $this->coolify->removeDomain($oldDomain);
                }
            }
        } elseif (empty($cd)) {
            // Domain was cleared
            if ($gallery->custom_domain) {
                $oldDomain = $gallery->custom_domain;
                \Illuminate\Support\Facades\Cache::forget("custom_domain:{$oldDomain}");
                if ($gallery->custom_domain_verified_at) {
                    $this->coolify->removeDomain($oldDomain);
                }
            }
            $validated['custom_domain'] = null;
            $request->attributes->set('_clear_domain_verification', true);
        } else {
            // Non-Studio plan trying to set a custom domain — block silently
            unset($validated['custom_domain']);
        }

        return null;
    }

    /**
     * P1-9: Apply guarded custom-domain verification fields after the gallery
     * update has been saved. These columns are NOT in $fillable, so update()
     * won't touch them — we use forceFill() based on flags stashed in the
     * request attributes by handleCustomDomain().
     */
    private function applyPostUpdateGuardedFields(Request $request, Gallery $gallery): void
    {
        if ($request->attributes->has('_pending_domain_token')) {
            $gallery->forceFill([
                'custom_domain_verification_token' => $request->attributes->get('_pending_domain_token'),
                'custom_domain_verified_at'        => null,
            ])->save();

            session()->flash('info', 'Custom domain saved. Add the TXT record shown below to your DNS, then click "Verify domain".');
        } elseif ($request->attributes->get('_clear_domain_verification')) {
            $gallery->forceFill([
                'custom_domain_verification_token' => null,
                'custom_domain_verified_at'        => null,
            ])->save();
        }
    }

    // ── Verify custom domain (Task C06) ───────────────────────────────────

    /**
     * Check DNS for the verification TXT record. If found, mark the domain
     * as verified and register it with Coolify.
     *
     * Called when the user clicks "Verify now" in the gallery edit page.
     * Also called by the scheduled `exospace:verify-pending-domains` command
     * for galleries with pending verifications.
     */
    public function verifyCustomDomain(Request $request, Gallery $gallery)
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);

        if (empty($gallery->custom_domain)) {
            return back()->with('error', 'No custom domain to verify.');
        }

        if ($gallery->isCustomDomainVerified()) {
            return back()->with('status', 'Domain is already verified.');
        }

        if (empty($gallery->custom_domain_verification_token)) {
            // Defensive — should never happen because the token is generated
            // when the domain is set. But if it does, generate one now.
            $gallery->generateDomainVerificationToken();
            $gallery->refresh();
        }

        $verified = $this->checkDnsTxtRecord(
            $gallery->domainVerificationTxtHost(),
            $gallery->domainVerificationTxtValue()
        );

        if (! $verified) {
            return back()->with('error', 'DNS verification failed. Make sure the TXT record has propagated (this can take 5–60 minutes for some DNS providers), then try again.');
        }

        // ── Verified! Mark + register with Coolify ──────────────────────
        $gallery->forceFill(['custom_domain_verified_at' => now()])->save();

        // Clear the lookup cache so DetectCustomDomain middleware picks up
        // the now-verified gallery on the next request.
        \Illuminate\Support\Facades\Cache::forget("custom_domain:{$gallery->custom_domain}");

        $coolifyResult = $this->coolify->addDomain($gallery->custom_domain);
        if (! $coolifyResult['success']) {
            \Log::warning('Coolify domain registration deferred on verification.', [
                'gallery_id' => $gallery->id,
                'domain'     => $gallery->custom_domain,
                'reason'     => $coolifyResult['message'],
            ]);
            session()->flash('warning', "Domain verified, but Coolify could not auto-configure the routing: {$coolifyResult['message']}");
        }

        return back()->with('status', "Domain \"{$gallery->custom_domain}\" verified! SSL cert will be provisioned automatically (may take 1–5 minutes).");
    }

    /**
     * Look up DNS TXT records for $host and return true if any record's
     * text matches $expectedValue exactly.
     *
     * Uses dns_get_record() which is available on PHP 8.2+ without
     * extensions. The lookup respects the system resolver's DNS cache
     * (so propagation delays apply — this is intentional, we want to
     * see what visitors will see).
     */
    private function checkDnsTxtRecord(string $host, string $expectedValue): bool
    {
        if (empty($host) || empty($expectedValue)) {
            return false;
        }

        // dns_get_record can return false on resolver failure. Suppress
        // warnings (the function emits E_WARNING on DNS errors) and treat
        // false as "no match" — the user can retry.
        $records = @dns_get_record($host, DNS_TXT);

        if (! is_array($records)) {
            \Log::info('Custom domain DNS lookup failed (no array returned)', [
                'host' => $host,
            ]);
            return false;
        }

        foreach ($records as $record) {
            // TXT records come back as either:
            //   ['host' => ..., 'txt' => 'exospace-verify=abc123']
            //   ['host' => ..., 'entries' => ['exospace-verify=abc123']]
            $candidates = [];
            if (isset($record['txt'])) {
                $candidates[] = trim($record['txt'], '"');
            }
            if (isset($record['entries']) && is_array($record['entries'])) {
                foreach ($record['entries'] as $entry) {
                    $candidates[] = trim($entry, '"');
                }
            }

            foreach ($candidates as $candidate) {
                if (hash_equals($expectedValue, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ── Destroy ───────────────────────────────────────────────────────────

    public function destroy(Gallery $gallery): RedirectResponse
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);
        $teamId = $gallery->team_id;

        // Clean up custom domain cache + remove from Coolify if the domain
        // was actually verified + registered. (Task C06.)
        if ($gallery->custom_domain) {
            $oldDomain = $gallery->custom_domain;
            \Illuminate\Support\Facades\Cache::forget("custom_domain:{$oldDomain}");
            if ($gallery->custom_domain_verified_at) {
                $this->coolify->removeDomain($oldDomain);
            }
        }

        $gallery->delete();

        // AUDIT-P1-4.14: Log gallery deletion. 'name' is PII — auto-scrubbed.
        AdminAuditLog::record('gallery.deleted', $gallery, [
            'name'                  => $gallery->name,
            'team_id'               => $teamId,
            'had_custom_domain'     => ! empty($gallery->custom_domain),
            'custom_domain_verified' => ! empty($gallery->custom_domain_verified_at),
        ]);

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

        $request->validate(['custom_logo' => 'required|file|mimes:png,jpg,jpeg|max:2048']);
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
            // PERF-E30 (3D audit): array_filter strips null fields — a
            // 100-artwork gallery otherwise ships ~500 dead bytes of nulls
            // per image (description, artist, price, medium, year...) inside
            // the inline GALLERY_DATA JSON. Every JS consumer already treats
            // missing keys and null identically (|| defaults, ?. chains).
            'images' => $gallery->images->map(fn($img) => array_filter([
                'id'             => $img->id,
                'url'            => asset($img->path),
                // PERF-A1 (3D audit F1): WebP conversion variants for the 3D
                // viewer — mirrors GalleryViewController::show. 'url' stays
                // the legacy fallback.
                'textures'       => [
                    'thumb'  => $img->conversionUrl('thumb'),
                    'small'  => $img->conversionUrl('small'),
                    'medium' => $img->conversionUrl('medium'),
                    'large'  => $img->conversionUrl('large'),
                ],
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
            ], fn ($v) => $v !== null))->values(),
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
            // SEO OS (Iteration 6): curator-facing SEO overrides. Only
            // title/description — robots/canonical/sitemap controls stay
            // in the super-admin SEO console (legitimate-use split).
            'seo_title'       => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:300',
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
            'custom_logo'     => 'nullable|file|mimes:png,jpg,jpeg|max:2048',
            // NEW: custom domain — Studio plan only, validated for shape here.
            // Plan-tier enforcement happens in the controller.
            'custom_domain'   => ['nullable', 'string', 'max:255', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i'],
            // NEW (Round 4) — Branded entrance curtain (Studio only)
            'curtain_logo'        => 'nullable|file|mimes:png,jpeg,webp|max:2048',
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
