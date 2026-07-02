<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Team authorization policy.
 *
 * (Task H05 / audit H9)
 *
 * Authorization model:
 *   - view:        team owner OR team member (any role)
 *   - create:      any authenticated user
 *   - update:      team owner OR team editor
 *   - delete:      team owner only
 *   - invite:      team owner OR team editor
 *   - manageMembers: team owner only
 *
 * Super-admins bypass all checks (see before() hook).
 */
class TeamPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_super_admin) {
            return true;
        }
        return null;
    }

    public function view(User $user, Team $team): bool
    {
        return $team->isOwner($user) || $team->hasMember($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Team $team): bool
    {
        return $team->canEdit($user);
    }

    public function delete(User $user, Team $team): bool
    {
        return $team->isOwner($user);
    }

    public function invite(User $user, Team $team): bool
    {
        return $team->canEdit($user);
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $team->isOwner($user);
    }

    /**
     * Can the user switch to this team as their active context?
     * Owner OR any member.
     */
    public function switch(User $user, Team $team): bool
    {
        return $team->isOwner($user) || $team->hasMember($user);
    }
}
