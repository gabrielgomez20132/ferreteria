<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\ExpenseCategory;
use App\Models\Plan;
use App\Models\PriceList;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permissions;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Alta de una empresa nueva con todos sus datos iniciales.
 */
class TenantProvisioner
{
    public function __construct(private TenantContext $context) {}

    public function provision(array $company, array $admin, ?Plan $plan = null): User
    {
        return DB::transaction(function () use ($company, $admin, $plan) {
            $plan ??= Plan::where('slug', 'pro')->first() ?? Plan::first();
            $tenant = Tenant::create([
                'name' => $company['name'],
                'cuit' => $company['cuit'] ?? null,
                'email' => $company['email'] ?? $admin['email'],
                'phone' => $company['phone'] ?? null,
                'address' => $company['address'] ?? null,
                'plan_id' => $plan?->id,
                'status' => $company['status'] ?? 'trial',
                'trial_ends_at' => now()->addDays(15),
                'settings' => Tenant::DEFAULT_SETTINGS,
            ]);

            $previous = [$this->context->tenant, $this->context->branch];
            $this->context->set($tenant);

            $branch = Branch::create(['name' => $company['branch_name'] ?? 'Casa central', 'code' => '0001', 'is_main' => true, 'address' => $company['address'] ?? null]);
            $this->context->set($tenant, $branch);

            $roles = [];
            foreach (Permissions::DEFAULT_ROLES as $name => $perms) {
                $roles[$name] = Role::create([
                    'name' => $name,
                    'permissions' => $perms,
                    'max_discount' => match ($name) { 'Administrador' => 100, 'Encargado' => 20, 'Vendedor' => 5, default => 0 },
                    'is_system' => $name === 'Administrador',
                ]);
            }

            foreach ([['Unidad', 'un', false], ['Metro', 'm', true], ['Kilogramo', 'kg', true], ['Litro', 'l', true], ['Caja', 'cja', false], ['Par', 'par', false], ['Metro cuadrado', 'm²', true]] as [$n, $a, $d]) {
                Unit::create(['name' => $n, 'abbreviation' => $a, 'allows_decimals' => $d]);
            }
            PriceList::create(['name' => 'Minorista', 'adjustment_percent' => 0, 'is_default' => true]);
            PriceList::create(['name' => 'Mayorista', 'adjustment_percent' => -10]);
            PriceList::create(['name' => 'Gremio / Profesionales', 'adjustment_percent' => -5]);
            CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja 1']);
            foreach (['Alquiler', 'Sueldos', 'Servicios', 'Impuestos', 'Fletes', 'Mantenimiento', 'Varios'] as $c) {
                ExpenseCategory::create(['name' => $c]);
            }

            $user = User::create([
                'tenant_id' => $tenant->id,
                'role_id' => $roles['Administrador']->id,
                'current_branch_id' => $branch->id,
                'name' => $admin['name'],
                'email' => $admin['email'],
                'password' => $admin['password'],
            ]);

            $this->context->set(...$previous);

            return $user;
        });
    }
}
