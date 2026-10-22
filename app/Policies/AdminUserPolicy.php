<?php

namespace App\Policies;

use App\Enums\AdminFeature;
use App\Models\AdminUser;

class AdminUserPolicy
{
    public function viewAny(mixed $user): bool
    {
        return $user instanceof AdminUser && $user->hasFeatureAccess(AdminFeature::AdminUsers);
    }

    public function view(mixed $user, AdminUser $adminUser): bool
    {
        return $user instanceof AdminUser && $user->hasFeatureAccess(AdminFeature::AdminUsers);
    }

    public function create(mixed $user): bool
    {
        return $user instanceof AdminUser && $user->isSuperAdmin();
    }

    public function update(mixed $user, AdminUser $adminUser): bool
    {
        if (! $user instanceof AdminUser || ! $user->hasFeatureAccess(AdminFeature::AdminUsers)) {
            return false;
        }

        return ! $adminUser->isSuperAdmin() || $user->isSuperAdmin();
    }

    public function delete(mixed $user, AdminUser $adminUser): bool
    {
        return $user instanceof AdminUser
            && $user->hasFeatureAccess(AdminFeature::AdminUsers)
            && ! $adminUser->isSuperAdmin()
            && ! $adminUser->is($user);
    }

    public function deleteAny(mixed $user): bool
    {
        return false;
    }
}
