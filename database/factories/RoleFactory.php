<?php

namespace Database\Factories;

use App\Enums\AdminFeature;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'permissions' => AdminFeature::values(),
            'is_super_admin' => false,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (): array => [
            'name' => 'superadmin',
            'permissions' => AdminFeature::values(),
            'is_super_admin' => true,
        ]);
    }
}
