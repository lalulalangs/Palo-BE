<?php

namespace Database\Seeders;

use App\Enums\AdminFeature;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RoleAndAdminUserSeeder extends Seeder
{
    /**
     * Seed role superadmin dan akun admin awal dari kredensial .env,
     * agar login /admin selalu konsisten setiap fresh migrate/seed.
     */
    public function run(): void
    {
        $superAdminRole = Role::firstOrCreate(
            ['name' => 'superadmin'],
            ['permissions' => AdminFeature::values()],
        );

        if (! $superAdminRole->is_super_admin) {
            $superAdminRole->is_super_admin = true;
            $superAdminRole->save();
        }

        AdminUser::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@palorinjani.local')],
            [
                'role_id' => $superAdminRole->id,
                'name' => env('ADMIN_NAME', 'Admin PaloRinjani'),
                'password_hash' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                'is_active' => true,
            ],
        );
    }
}
