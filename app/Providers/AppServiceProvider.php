<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Un contexto por request (compatible con Octane / colas)
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {

        // Permite usar @can / $user->can('products.manage') con el sistema de permisos propio
        Gate::before(function ($user, string $ability) {
            if (str_contains($ability, '.') && $user->tenant_id) {
                return $user->hasPermission($ability) ?: null;
            }

            return null;
        });
    }
}
