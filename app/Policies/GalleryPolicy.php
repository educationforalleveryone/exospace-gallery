<?php

namespace App\Policies;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GalleryPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return null;
    }

    public function view(User $user, Gallery $gallery): bool
    {
        if ($gallery->team_id) {
            return $gallery->team && $user->belongsToTeam($gallery->team);
        }

        return $gallery->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Gallery $gallery): bool
    {
        if ($gallery->team_id) {
            return $gallery->team && $gallery->team->canEdit($user);
        }

        return $gallery->user_id === $user->id;
    }

    public function delete(User $user, Gallery $gallery): bool
    {
        return $this->update($user, $gallery);
    }

    public function duplicate(User $user, Gallery $gallery): bool
    {
        return $this->update($user, $gallery);
    }

    public function uploadMedia(User $user, Gallery $gallery): bool
    {
        return $this->update($user, $gallery);
    }

    public function manageEvents(User $user, Gallery $gallery): bool
    {
        return $this->update($user, $gallery);
    }

    public function viewAnalytics(User $user, Gallery $gallery): bool
    {
        return $this->view($user, $gallery);
    }
}
