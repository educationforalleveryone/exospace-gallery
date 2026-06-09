<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait AuthorizesGalleryAccess
{
    /**
     * Abort 403 unless the authenticated user may access this gallery.
     * Pass requireEdit=true to also enforce editor/owner role on team galleries.
     */
    protected function authorizeGalleryAccess(Gallery $gallery, bool $requireEdit = false): void
    {
        $user = Auth::user();

        if ($gallery->team_id) {
            $team = $gallery->team;
            if (! $user->belongsToTeam($team)) abort(403);
            if ($requireEdit && ! $team->canEdit($user)) abort(403);
        } else {
            if ($gallery->user_id !== $user->id) abort(403);
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
