<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VenueTemplate;
use Illuminate\Auth\Access\HandlesAuthorization;

class VenueTemplatePolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
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
