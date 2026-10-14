<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'tenant_id', 'role_id', 'current_branch_id', 'name', 'email', 'password', 'is_super_admin', 'is_active', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** Valores por defecto (coinciden con la base de datos) */
    protected $attributes = ['is_active' => true, 'is_super_admin' => false];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function currentBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'current_branch_id');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    public function permissions(): array
    {
        if (! $this->role) {
            return [];
        }
        $perms = $this->role->permissions ?? [];

        return in_array('*', $perms, true) ? \App\Support\Permissions::all() : $perms;
    }

    public function hasPermission(string $permission): bool
    {
        if (! $this->role) {
            return false;
        }
        $perms = $this->role->permissions ?? [];

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public function maxDiscount(): float
    {
        return $this->hasPermission('sales.price_override') ? 100 : (float) ($this->role?->max_discount ?? 0);
    }

    /** Sucursales a las que puede acceder (si no tiene asignadas, todas las activas) */
    public function accessibleBranches()
    {
        $assigned = $this->branches()->where('is_active', true)->orderBy('name')->get();
        if ($assigned->isNotEmpty() && ! $this->hasPermission('branches.manage')) {
            return $assigned;
        }

        return Branch::where('tenant_id', $this->tenant_id)->where('is_active', true)->orderByDesc('is_main')->orderBy('name')->get();
    }
}
