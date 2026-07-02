<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VenueTemplate;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * VenueTemplate authorization policy.
 *
 * (Task H05 / audit H9)
 *
 * Venue templates are super-admin-only for mutations. Any authenticated
 * user can view them (needed for the gallery edit form's venue picker).
 *
 * Authorization model:
 *   - view / viewAny: any authenticated user
 *   - create / update / delete / toggle: super-admin only (via before() hook)
 */
class VenueTemplatePolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        // Only super-admins can mutate venue templates. View is allowed
        // for all authenticated users (handled by the view/viewAny methods
        // below, which return true).
        if (in_array($ability, ['create', 'update', 'delete', 'toggle', 'toggleFeatured'], true)) {
            return $user->is_super_admin;
        }
        return null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, VenueTemplate $venueTemplate): bool
    {
        return true;
    }
}
