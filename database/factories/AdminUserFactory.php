<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<AdminUser>
 */
class AdminUserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'permissions' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'last_login_at' => null,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(function (): array {
            $roleId = Role::query()->where('is_super_admin', true)->value('id');

            return [
                'role_id' => $roleId ?? Role::factory()->superAdmin(),
            ];
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
