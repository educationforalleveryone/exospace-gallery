<?php

namespace App\Policies;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Gallery authorization policy.
 *
 * (Task H05 / audit H9) — replaces the ad-hoc AuthorizesGalleryAccess trait
 * with a proper Laravel policy. Policies are auto-discovered by Laravel 11+
 * and used by `$this->authorize('view', $gallery)` in controllers, or
 * `@can('view', $gallery)` in Blade.
 *
 * Authorization model:
 *   - view:        personal → owner; team → any current team member
 *   - viewOnAdmin: same as view — for admin index/show
 *   - create:      any authenticated user (plan limit checked separately)
 *   - update:      personal → owner; team → team owner/editor (canEdit)
 *   - delete:      same as update
 *   - duplicate:   same as update
 *   - uploadMedia: same as update (audio, logo, images)
 *   - manageEvents:same as update
 *
 * ITERATION-12 (gallery ownership boundary): for TEAM galleries the
 * row-level user_id (the member who created the gallery) grants nothing
 * by itself — authority flows through CURRENT team membership/roles, so
 * removing or demoting a member revokes their access to team galleries
 * they once created. Personal galleries remain user_id-owned.
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
     *
     * ITERATION-12 (gallery ownership boundary): the row-level user_id is
     * the ownership anchor for PERSONAL galleries only. Team galleries
     * resolve through team membership exclusively — previously the
     * user_id branch matched FIRST, so a member who had once created a
     * team gallery kept view access after being removed from the team
     * (removeMember/leave only detach the pivot; nothing else revokes it).
     */
    public function view(User $user, Gallery $gallery): bool
    {
        if ($gallery->team_id) {
            // Defensive null-team guard: a dangling team_id (should not
            // exist — team deletion nulls team_id) fails closed for
            // non-owners instead of 500-ing on belongsToTeam(null).
            return $gallery->team && $user->belongsToTeam($gallery->team);
        }

        return $gallery->user_id === $user->id;
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
     *
     * ITERATION-12 (gallery ownership boundary): team galleries decide
     * EDIT rights through team roles only (owner or editor). Previously
     * the user_id branch matched FIRST, so a member who had created a
     * team gallery kept update/delete rights even after being removed
     * from the team or demoted to viewer — contradicting this policy's
     * documented model ("update: owner OR team editor/owner") and the
     * role system the team owner manages. The team owner always retains
     * access via canEdit(); a removed/demoted creator loses only what
     * the team explicitly took away.
     */
    public function update(User $user, Gallery $gallery): bool
    {
        if ($gallery->team_id) {
            return $gallery->team && $gallery->team->canEdit($user);
        }

        return $gallery->user_id === $user->id;
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
