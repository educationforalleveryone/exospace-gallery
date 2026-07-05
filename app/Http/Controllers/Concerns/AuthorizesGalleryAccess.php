<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Gallery access authorization trait.
 *
 * (Task H05 / audit H9) — now delegates to the GalleryPolicy (created in Iteration 05)
 * instead of inline checks. This keeps the trait's public API backward-
 * compatible (controllers that call `$this->authorizeGalleryAccess($gallery)`
 * continue to work) while using the policy as the single source of truth.
 *
 * TD-8 FIX: This trait is now marked @deprecated. New code should call
 * `$this->authorize('view', $gallery)` or `$this->authorize('update', $gallery)`
 * directly, which uses the GalleryPolicy via Laravel's Gate system. The
 * trait is kept only because converting the ~18 existing call sites across
 * 5 controllers is a large refactor (high risk of breaking things) with
 * no behavior change — the trait already delegates to the policy correctly.
 *
 * When to remove: a future code-quality-focused iteration should convert
 * all call sites to `$this->authorize(...)` and delete this trait. Until
 * then, it's harmless (the policy is the source of truth) but adds a
 * layer of indirection that makes the authorization flow harder to trace.
 *
 * @deprecated since Iteration 016. Use `$this->authorize('view'/'update', $gallery)` instead.
 */
trait AuthorizesGalleryAccess
{
    /**
     * Abort 403 unless the authenticated user may access this gallery.
     * Pass requireEdit=true to also enforce editor/owner role on team galleries.
     *
     * Delegates to the GalleryPolicy:
     *   - requireEdit=false → GalleryPolicy::view
     *   - requireEdit=true  → GalleryPolicy::update
     *
     * @deprecated Use $this->authorize('view', $gallery) or $this->authorize('update', $gallery) directly.
     */
    protected function authorizeGalleryAccess(Gallery $gallery, bool $requireEdit = false): void
    {
        $user = Auth::user();

        if ($requireEdit) {
            // Use the policy's update check (owner OR team editor/owner)
            if (! $user->can('update', $gallery)) {
                abort(403);
            }
        } else {
            // Use the policy's view check (owner OR team member)
            if (! $user->can('view', $gallery)) {
                abort(403);
            }
        }
    }

    /**
     * Resolve the plan-holder for a gallery:
     * team gallery → team owner, personal gallery → current user.
     */
    protected function galleryPlanHolder(Gallery $gallery): User
    {
        return $gallery->team_id
            ? $gallery->team->owner
            : Auth::user();
    }
}
