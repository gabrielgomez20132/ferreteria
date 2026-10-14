<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Tenant;

/**
 * Contexto de la empresa (tenant) y sucursal activas para el request actual.
 * Todos los modelos con BelongsToTenant se filtran automáticamente por este contexto.
 */
class TenantContext
{
    public ?Tenant $tenant = null;

    public ?Branch $branch = null;

    public function set(?Tenant $tenant, ?Branch $branch = null): void
    {
        $this->tenant = $tenant;
        $this->branch = $branch;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function branchId(): ?int
    {
        return $this->branch?->id;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $settings = array_merge(Tenant::DEFAULT_SETTINGS, $this->tenant?->settings ?? []);

        return $settings[$key] ?? $default;
    }
}
