<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoleManagementMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_down_removes_every_column_added_by_up(): void
    {
        $migration = require database_path(
            'migrations/2026_10_10_105035_add_role_management_columns_to_roles_and_admin_users_tables.php'
        );

        $this->assertTrue(Schema::hasColumn('roles', 'is_super_admin'));
        $this->assertTrue(Schema::hasColumn('admin_users', 'permissions'));
        $this->assertTrue(Schema::hasColumn('admin_users', 'remember_token'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('roles', 'is_super_admin'));
        $this->assertFalse(Schema::hasColumn('admin_users', 'permissions'));
        $this->assertFalse(Schema::hasColumn('admin_users', 'remember_token'));
    }
}
