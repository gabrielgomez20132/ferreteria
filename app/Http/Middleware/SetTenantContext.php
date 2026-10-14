<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Determina la empresa y sucursal del usuario autenticado y valida el estado de la suscripción.
 */
class SetTenantContext
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active) {
            Auth::logout();

            return redirect()->route('login')->with('error', 'Su usuario está deshabilitado.');
        }

        if ($user->tenant_id) {
            $tenant = $user->tenant;
            if (! $tenant || $tenant->trashed() || in_array($tenant->status, ['suspended', 'cancelled'], true)) {
                Auth::logout();

                return redirect()->route('login')->with('error', 'La cuenta de la empresa está suspendida. Contacte a soporte.');
            }

            $branches = $user->accessibleBranches();
            $branch = $branches->firstWhere('id', $user->current_branch_id) ?? $branches->first();
            if ($branch && $branch->id !== $user->current_branch_id) {
                $user->forceFill(['current_branch_id' => $branch->id])->saveQuietly();
            }
            $this->context->set($tenant, $branch);

            if (! $tenant->isOperational() && ! $request->routeIs('subscription.*', 'logout')) {
                return redirect()->route('subscription.expired');
            }
        } elseif ($user->is_super_admin && ! $request->routeIs('admin.*', 'logout', 'home')) {
            return redirect()->route('admin.dashboard');
        }

        return $next($request);
    }
}
