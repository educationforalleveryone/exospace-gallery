<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Gallery access authorization trait.
 *
 * (Task H24) — now delegates to the GalleryPolicy (created in Iteration 05)
 * instead of inline checks. This keeps the trait's public API backward-
 * compatible (controllers that call `$this->authorizeGalleryAccess($gallery)`
 * continue to work) while using the policy as the single source of truth.
 *
 * New code should prefer `$this->authorize('update', $gallery)` directly,
 * which uses the policy via Laravel's Gate system. This trait is kept for
 * the existing call sites that pass `requireEdit` as a boolean.
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
