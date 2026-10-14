<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('permission:products.manage') o 'permission:sales.view|sales.create'
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        foreach (explode('|', $permissions) as $permission) {
            if ($user && $user->hasPermission($permission)) {
                return $next($request);
            }
        }
        abort(403, 'No tiene permiso para realizar esta acción.');
    }
}
