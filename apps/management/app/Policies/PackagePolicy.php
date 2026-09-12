<?php

namespace App\Policies;

use App\Models\ServicePackage;
use App\Models\User;

class PackagePolicy
{
    /**
     * Single-tenant admin model: any admin user may manage records.
     */
    public function before(User $user): ?bool
    {
        return ! $user->is_active ? false : ($user->is_admin ? true : null);
    }

    public function viewAny(User $user): bool
    {
        return $user->canViewBusiness();
    }

    public function view(User $user, ServicePackage $package): bool
    {
        return $user->canViewBusiness();
    }

    public function create(User $user): bool
    {
        return $user->canEditBusiness();
    }

    public function update(User $user, ServicePackage $package): bool
    {
        return $user->canEditBusiness();
    }

    public function delete(User $user, ServicePackage $package): bool
    {
        return $user->canEditBusiness();
    }
}
