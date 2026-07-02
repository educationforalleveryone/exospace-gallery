<?php

namespace App\Policies;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * Gallery authorization policy.
 *
 * (Task H05 / audit H9) — replaces the ad-hoc AuthorizesGalleryAccess trait
 * with a proper Laravel policy. Policies are auto-discovered by Laravel 11+
 * and used by `$this->authorize('view', $gallery)` in controllers, or
 * `@can('view', $gallery)` in Blade.
 *
 * Authorization model:
 *   - view:        owner OR team member (any role)
 *   - viewOnAdmin: owner OR team member (any role) — for admin index/show
 *   - create:      any authenticated user (plan limit checked separately)
 *   - update:      owner OR team editor/owner
 *   - delete:      owner OR team editor/owner
 *   - duplicate:   owner OR team editor/owner
 *   - uploadMedia: owner OR team editor/owner (audio, logo, images)
 *   - manageEvents:owner OR team editor/owner
 *
 * Super-admins bypass all checks (see before() hook).
 *
 * The old AuthorizesGalleryAccess trait is kept for backward compatibility
 * — controllers that still call `$this->authorizeGalleryAccess($gallery)`
 * continue to work. New code should use `$this->authorize('update', $gallery)`.
 */
class GalleryPolicy
{
    use HandlesAuthorization;

    /**
     * Super-admins bypass all checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_super_admin) {
            return true;
        }
        return null;
    }

    /**
     * Can the user view this gallery in the admin panel?
     */
    public function view(User $user, Gallery $gallery): bool
    {
        if ($gallery->user_id === $user->id) {
            return true;
        }

        if ($gallery->team_id) {
            return $user->belongsToTeam($gallery->team);
        }

        return false;
    }

    /**
     * Can the user create a gallery? Plan limit is checked separately in
     * the controller via User::canCreateGallery().
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Can the user update this gallery's settings?
     * Owner OR team editor/owner.
     */
    public function update(User $user, Gallery $gallery): bool
    {
        if ($gallery->user_id === $user->id) {
            return true;
        }

        if ($gallery->team_id) {
            return $gallery->team->canEdit($user);
        }

        return false;
    }

    /**
     * Can the user delete this gallery?
     * Same as update — owner OR team editor/owner.
     */
    public function delete(User $user, Gallery $gallery): bool
    {
        return $this->update($user, $gallery);
    }

    /**
     * Can the user duplicate this gallery?
     * Same as update — owner OR team editor/owner.
     */
    public function duplicate(User $user, Gallery $gallery): bool
    {
        return $this->update($user, $gallery);
    }

    /**
     * Can the user upload media (audio, logo, images) to this gallery?
     * Same as update — owner OR team editor/owner.
     */
    public function uploadMedia(User $user, Gallery $gallery): bool
    {
        return $this->update($user, $gallery);
    }

    /**
     * Can the user manage schedule events for this gallery?
     * Same as update — owner OR team editor/owner.
     */
    public function manageEvents(User $user, Gallery $gallery): bool
    {
        return $this->update($user, $gallery);
    }

    /**
     * Can the user view analytics for this gallery?
     * Same as view — owner OR team member (any role).
     */
    public function viewAnalytics(User $user, Gallery $gallery): bool
    {
        return $this->view($user, $gallery);
    }
}
