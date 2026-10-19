<?php

namespace Tests\Unit;

use Database\Seeders\RoleAndAdminUserSeeder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AdminSeederPasswordTest extends TestCase
{
    public function test_production_without_env_password_throws(): void
    {
        $this->expectException(RuntimeException::class);

        RoleAndAdminUserSeeder::resolveAdminPassword(null, 'production');
    }

    public function test_production_uses_env_password(): void
    {
        $this->assertSame('s3cret-production', RoleAndAdminUserSeeder::resolveAdminPassword('s3cret-production', 'production'));
    }

    public function test_local_falls_back_to_development_password(): void
    {
        $this->assertSame('password', RoleAndAdminUserSeeder::resolveAdminPassword(null, 'local'));
    }
}
