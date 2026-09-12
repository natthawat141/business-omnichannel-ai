<?php

namespace App\Policies;

use App\Models\DocumentSource;
use App\Models\User;

class DocumentSourcePolicy
{
    /**
     * Single-tenant admin model: any admin user may manage document sources.
     */
    public function before(User $user): ?bool
    {
        return $user->is_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, DocumentSource $document): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, DocumentSource $document): bool
    {
        return $user->is_admin;
    }
}
