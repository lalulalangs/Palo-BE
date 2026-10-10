<?php

namespace App\Models;

use App\Enums\AdminFeature;
use Database\Factories\AdminUserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class AdminUser extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<AdminUserFactory> */
    use HasFactory;

    protected $fillable = [
        'role_id',
        'permissions',
        'name',
        'email',
        'password_hash',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'password_hash' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Kolom password ERD bernama password_hash — arahkan Laravel Auth ke sana.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->is_active;
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->role?->is_super_admin;
    }

    public function hasFeatureAccess(AdminFeature $feature): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $rolePermissions = $this->role?->permissions ?? [];
        $directPermissions = $this->permissions ?? [];

        return in_array($feature->value, array_merge($rolePermissions, $directPermissions), true);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(AdminActivityLog::class);
    }

    public function orderStatusChanges(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'changed_by_admin_id');
    }
}
