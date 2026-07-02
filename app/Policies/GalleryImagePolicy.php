<?php

namespace App\Policies;

use App\Models\GalleryImage;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * GalleryImage authorization policy.
 *
 * (Task H05 / audit H9)
 *
 * Authorization model:
 *   - All mutations (delete, update metadata, reorder) are gated by the
 *     parent gallery's update policy. If you can edit the gallery, you
 *     can manage its images.
 *
 * Super-admins bypass all checks (see before() hook).
 */
class GalleryImagePolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_super_admin) {
            return true;
        }
        return null;
    }

    public function update(User $user, GalleryImage $image): bool
    {
        $gallery = $image->gallery;
        if (! $gallery) {
            return false;
        }

        if ($gallery->user_id === $user->id) {
            return true;
        }

        if ($gallery->team_id) {
            return $gallery->team->canEdit($user);
        }

        return false;
    }

    public function delete(User $user, GalleryImage $image): bool
    {
        return $this->update($user, $image);
    }
}
