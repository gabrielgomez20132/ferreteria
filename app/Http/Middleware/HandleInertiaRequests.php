<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $ctx = app(TenantContext::class);

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_super_admin' => $user->is_super_admin,
                    'role' => $user->role?->name,
                    'max_discount' => $user->tenant_id ? $user->maxDiscount() : 0,
                ] : null,
                'permissions' => $user && $user->tenant_id ? $user->permissions() : [],
            ],
            'tenant' => $ctx->tenant ? [
                'id' => $ctx->tenant->id,
                'name' => $ctx->tenant->name,
                'status' => $ctx->tenant->status,
                'trial_ends_at' => $ctx->tenant->trial_ends_at?->toDateString(),
                'plan' => $ctx->tenant->plan?->name,
                'settings' => $ctx->tenant->allSettings(),
            ] : null,
            'branch' => $ctx->branch?->only(['id', 'name', 'code']),
            'branches' => fn () => $user && $user->tenant_id ? $user->accessibleBranches()->map->only(['id', 'name'])->values() : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'data' => fn () => $request->session()->get('data'),
            ],
        ];
    }
}
