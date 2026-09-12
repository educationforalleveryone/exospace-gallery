<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait AuthorizesGalleryAccess
{
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

    protected function galleryPlanHolder(Gallery $gallery): User
    {
        return $gallery->team_id
            ? $gallery->team->owner
            : Auth::user();
    }
}
