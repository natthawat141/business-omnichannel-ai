<?php

namespace App\Policies;

use App\Models\BusinessProfile;
use App\Models\User;

class BusinessProfilePolicy
{
    /**
     * Single-tenant admin model: any admin user may manage the profile.
     */
    public function before(User $user): ?bool
    {
        return ! $user->is_active ? false : ($user->is_admin ? true : null);
    }

    public function view(User $user, BusinessProfile $profile): bool
    {
        return $user->canViewBusiness();
    }

    public function update(User $user, BusinessProfile $profile): bool
    {
        return $user->canEditBusiness();
    }
}
