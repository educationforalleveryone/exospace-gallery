<?php

namespace App\Policies;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Artist authorization policy.
 *
 * (Task H05 / audit H9 + C16)
 *
 * Authorization model:
 *   - view / search: any authenticated user (multi-curator collaboration —
 *     the dropdown shows everyone's artists)
 *   - create:        any authenticated user (created_by is set to the user)
 *   - update:        creator OR super-admin
 *   - delete:        creator OR super-admin
 *
 * This policy formalizes the C16 fix (which added the
 * `authorizeArtistMutation` private helper to ArtistController). New code
 * should use `$this->authorize('update', $artist)` instead of the helper.
 */
class ArtistPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_super_admin) {
            return true;
        }
        return null;
    }

    public function view(User $user, Artist $artist): bool
    {
        return true;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Artist $artist): bool
    {
        return $artist->created_by === $user->id;
    }

    public function delete(User $user, Artist $artist): bool
    {
        return $artist->created_by === $user->id;
    }
}
