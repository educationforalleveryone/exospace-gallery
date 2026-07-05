<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Centralize the cleanup of Studio-plan-only resources when a user is
 * downgraded to Free.
 *
 * Three downgrade paths converge here:
 *   1. WebhookController::applyRefund / applyChargeback
 *   2. CheckPlanExpiry middleware (plan_expires_at in the past)
 *   3. SuperAdmin\SystemController::updatePlan (admin changes plan)
 *
 * WITHOUT this cleanup, a refunded Studio user keeps:
 *   - Their custom_domain in the DB → DetectCustomDomain middleware keeps
 *     serving the gallery on the Studio-only domain forever.
 *   - Their custom_logo_path / curtain_logo_path / audio_path files on disk
 *     → disk leak, and re-upgrade shows stale branding.
 *   - The cached `custom_domain:{host}` lookup → 5-min window where the
 *     old domain still resolves.
 *
 * WITH this cleanup:
 *   - Each gallery's custom_domain is cleared in the DB.
 *   - Coolify's Traefik domain list is updated via CoolifyDomainManager::removeDomain().
 *   - The cached host→gallery lookup is forgotten.
 *   - The custom_logo_path / curtain_logo_path / audio_path files are deleted
 *     from the public disk.
 *
 * WHY A SERVICE (not a model observer)
 * ------------------------------------
 * The cleanup has external side effects (Coolify API call, file deletion,
 * cache forget). Model observers fire on every save() — including saves
 * where the plan hasn't changed. A service called explicitly from the three
 * downgrade paths is clearer, testable in isolation, and doesn't risk
 * firing on unrelated model updates.
 */
class PlanDowngradeService
{
    public function __construct(
        private readonly CoolifyDomainManager $coolify,
    ) {}

    /**
     * Downgrade a user to Free and clean up all Studio-only resources.
     *
     * @param  User    $user
     * @param  string  $reason  Short reason for log context (e.g. "Refund issued",
     *                          "Plan expired", "Admin plan change"). Logged but
     *                          not stored in the DB.
     */
    public function downgradeToFree(User $user, string $reason): void
    {
        $limits = User::planLimits('free');

        // 1. Update the user's plan + limits + expiry. Use forceFill because
        //    plan / max_galleries / max_images are guarded (task C09).
        $user->forceFill([
            'plan'            => 'free',
            'max_galleries'   => $limits['max_galleries'],
            'max_images'      => $limits['max_images'],
            'plan_expires_at' => now(),
        ])->save();

        Log::info('PlanDowngradeService: user downgraded to free', [
            'user_id' => $user->id,
            'reason'  => $reason,
        ]);

        // 2. Clean up Studio-only resources on every gallery owned by the user.
        //    Team galleries are also cleaned up because the user is the owner
        //    (team_id != null but user_id still points at the original creator).
        //
        //    P0-1 FIX (audit): The four whereNotNull / orWhereNotNull clauses
        //    MUST be wrapped in a single where(closure) so the user_id
        //    constraint from $user->galleries() applies to ALL four
        //    conditions. Without the closure, SQL operator precedence makes
        //    AND bind tighter than OR, producing:
        //
        //      WHERE (user_id = ? AND custom_domain IS NOT NULL)
        //         OR (custom_logo_path IS NOT NULL)          -- UN_SCOPED!
        //         OR (curtain_logo_path IS NOT NULL)         -- UN_SCOPED!
        //         OR (audio_path IS NOT NULL)                -- UN_SCOPED!
        //
        //    which matches ANY gallery in the database with any of those
        //    fields populated — wiping branding across every paying customer
        //    on every downgrade. The closure produces the correct:
        //
        //      WHERE user_id = ?
        //        AND (custom_domain IS NOT NULL
        //             OR custom_logo_path IS NOT NULL
        //             OR curtain_logo_path IS NOT NULL
        //             OR audio_path IS NOT NULL)
        //
        //    The closure is also required for chunkById() correctness:
        //    chunkById paginates with WHERE id > ?, and an unscoped OR
        //    would produce an incorrect result set across pages.
        $user->galleries()
            ->where(function ($q) {
                $q->whereNotNull('custom_domain')
                  ->orWhereNotNull('custom_logo_path')
                  ->orWhereNotNull('curtain_logo_path')
                  ->orWhereNotNull('audio_path');
            })
            ->chunkById(50, function ($galleries) use ($reason) {
                foreach ($galleries as $gallery) {
                    $this->cleanupGalleryStudioResources($gallery, $reason);
                }
            });
    }

    /**
     * Clean up Studio-only resources on a single gallery.
     *
     * Public so it can be called directly when a user manually clears their
     * custom_domain (in GalleryController::update).
     *
     * @param  Gallery  $gallery
     * @param  string   $reason  For log context only.
     */
    public function cleanupGalleryStudioResources(Gallery $gallery, string $reason = ''): void
    {
        // ── Custom domain ─────────────────────────────────────────────────
        // Order matters: read the domain BEFORE clearing the column, because
        // CoolifyDomainManager::removeDomain needs the original domain string
        // and the cache forget uses the normalized host.
        $customDomain = $gallery->getOriginal('custom_domain');

        if (! empty($customDomain)) {
            // Tell Coolify to remove the domain from Traefik's routing.
            // Failures are logged but do NOT abort the downgrade — the user
            // is already on Free in the DB; an orphaned Coolify domain is a
            // ops cleanup task, not a billing-incorrect state.
            try {
                $result = $this->coolify->removeDomain($customDomain);
                if (! $result['success']) {
                    Log::warning('PlanDowngradeService: CoolifyDomainManager::removeDomain failed', [
                        'gallery_id' => $gallery->id,
                        'domain'     => $customDomain,
                        'message'    => $result['message'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('PlanDowngradeService: CoolifyDomainManager::removeDomain threw', [
                    'gallery_id' => $gallery->id,
                    'domain'     => $customDomain,
                    'error'      => $e->getMessage(),
                ]);
            }

            // Forget the cached host→gallery lookup so DetectCustomDomain
            // middleware stops resolving this gallery on the old domain
            // immediately (rather than waiting up to 5 min for cache expiry).
            $normalizedHost = $this->normalizeHostForCache($customDomain);
            Cache::forget("custom_domain:{$normalizedHost}");
            // Also forget the un-normalized form in case it was cached before
            // the normalize() helper existed.
            Cache::forget("custom_domain:{$customDomain}");

            // Clear the column AND the verification fields (task C06).
            // The domain is being unassigned — there's nothing to keep
            // verified. If the user re-upgrades and re-claims the same
            // domain later, they'll need to re-add the TXT record (which
            // proves they still own it).
            $gallery->forceFill([
                'custom_domain'                     => null,
                'custom_domain_verification_token'  => null,
                'custom_domain_verified_at'         => null,
            ])->save();

            Log::info('PlanDowngradeService: cleared custom_domain', [
                'gallery_id' => $gallery->id,
                'domain'     => $customDomain,
                'reason'     => $reason,
            ]);
        }

        // ── Studio-only file fields ───────────────────────────────────────
        // custom_logo_path, curtain_logo_path are Studio-only branding.
        // audio_path is a Pro feature too — but on downgrade to Free we
        // remove it as well, since Free plan doesn't include audio.
        //
        // We delete the file AND null the column atomically per field.
        // File-deletion failures are logged but do NOT abort — the column
        // is still nulled so the gallery stops referencing a missing file.
        $fileFields = ['custom_logo_path', 'curtain_logo_path', 'audio_path'];

        $updates = [];
        foreach ($fileFields as $field) {
            $path = $gallery->getOriginal($field);
            if (empty($path)) {
                continue;
            }

            $this->deletePublicDiskFile($path);
            $updates[$field] = null;

            Log::info('PlanDowngradeService: cleared file field', [
                'gallery_id' => $gallery->id,
                'field'      => $field,
                'reason'     => $reason,
            ]);
        }

        if (! empty($updates)) {
            $gallery->forceFill($updates)->save();
        }
    }

    /**
     * Delete a file from the public disk, handling both disk-relative and
     * `storage/`-prefixed path conventions (the codebase has both — task M6
     * in the audit notes this inconsistency).
     */
    private function deletePublicDiskFile(string $path): void
    {
        if (empty($path)) {
            return;
        }

        $disk = Storage::disk('public');

        // Strip a leading "storage/" if present — the public disk's root is
        // already storage/app/public, so "storage/foo.jpg" would resolve to
        // storage/app/public/storage/foo.jpg which doesn't exist.
        // Use Str::after (preg_match anchored to start) instead of
        // str_replace (which would over-replace "storage/foo/storage/bar").
        $clean = \Illuminate\Support\Str::after($path, 'storage/');

        try {
            if ($disk->exists($clean)) {
                $disk->delete($clean);
            } elseif ($disk->exists($path)) {
                // Defensive fallback for paths stored without the storage/ prefix.
                $disk->delete($path);
            }
        } catch (\Throwable $e) {
            Log::warning('PlanDowngradeService: file delete failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalize a custom_domain value for cache-key matching with the
     * DetectCustomDomain middleware.
     *
     * The middleware strips scheme, path, port, AND leading www. The Gallery
     * model's saving hook strips scheme/path/port but KEEPS www (per the
     * comment "Actually keep www. — let DNS decide"). To be safe, we forget
     * BOTH forms (with and without www.) — see the caller.
     */
    private function normalizeHostForCache(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];
        $domain = preg_replace('/^www\./', '', $domain);
        return $domain;
    }
}
