<?php

namespace App\Policies;

use App\Enums\AdminFeature;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Auth\Access\Response;

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

    public function delete(mixed $user, Role $role): Response|bool
    {
        if (! $user instanceof AdminUser || ! $user->isSuperAdmin() || $role->is_super_admin) {
            return false;
        }

        if ($role->adminUsers()->exists()) {
            return Response::deny('Role masih dipakai oleh pengguna aktif, jadi belum bisa dihapus.');
        }

        return true;
    }

    public function deleteAny(mixed $user): bool
    {
        return false;
    }
}
