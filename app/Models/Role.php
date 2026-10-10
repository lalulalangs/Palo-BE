<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_super_admin' => 'boolean',
        ];
    }

    public function adminUsers(): HasMany
    {
        return $this->hasMany(AdminUser::class);
    }
}
