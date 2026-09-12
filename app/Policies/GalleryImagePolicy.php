<?php

namespace App\Policies;

use App\Models\GalleryImage;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

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

        if ($gallery->team_id) {
            return $gallery->team && $gallery->team->canEdit($user);
        }

        return $gallery->user_id === $user->id;
    }

    public function delete(User $user, GalleryImage $image): bool
    {
        return $this->update($user, $image);
    }
}
