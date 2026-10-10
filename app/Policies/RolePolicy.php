<?php

namespace App\Policies;

use App\Enums\AdminFeature;
use App\Models\AdminUser;
use App\Models\Role;

class RolePolicy
{
    public function viewAny(mixed $user): bool
    {
        return $user instanceof AdminUser && $user->hasFeatureAccess(AdminFeature::Roles);
    }

    public function view(mixed $user, Role $role): bool
    {
        return $user instanceof AdminUser && $user->hasFeatureAccess(AdminFeature::Roles);
    }

    public function create(mixed $user): bool
    {
        return $user instanceof AdminUser && $user->isSuperAdmin();
    }

    public function update(mixed $user, Role $role): bool
    {
        return $user instanceof AdminUser && $user->isSuperAdmin() && ! $role->is_super_admin;
    }

    public function delete(mixed $user, Role $role): bool
    {
        return $user instanceof AdminUser && $user->isSuperAdmin() && ! $role->is_super_admin;
    }

    public function deleteAny(mixed $user): bool
    {
        return false;
    }
}
