<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Panel del dueño de la plataforma SaaS: empresas suscriptas, estado y planes.
 */
class TenantController extends Controller
{
    public function dashboard()
    {
        $byStatus = Tenant::selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $mrr = (float) Tenant::where('status', 'active')->join('plans', 'plans.id', '=', 'tenants.plan_id')->sum('plans.price');

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'tenants' => Tenant::count(),
                'active' => (int) ($byStatus['active'] ?? 0),
                'trial' => (int) ($byStatus['trial'] ?? 0),
                'suspended' => (int) ($byStatus['suspended'] ?? 0),
                'mrr' => $mrr,
                'users' => DB::table('users')->whereNotNull('tenant_id')->count(),
                'sales_30d' => DB::table('sales')->where('status', 'completed')->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'recent' => Tenant::with('plan:id,name')->latest()->limit(10)->get(),
            'expiring' => Tenant::where('status', 'trial')->whereBetween('trial_ends_at', [now(), now()->addDays(5)])->get(['id', 'name', 'email', 'trial_ends_at']),
        ]);
    }

    public function index(Request $request)
    {
        return Inertia::render('Admin/Tenants', [
            'tenants' => Tenant::with('plan:id,name')
                ->withCount(['users', 'branches'])
                ->addSelect(['last_sale_at' => DB::table('sales')->selectRaw('MAX(created_at)')->whereColumn('sales.tenant_id', 'tenants.id')])
                ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('cuit', 'like', "%{$s}%")))
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->latest()->paginate(30)->withQueryString(),
            'plans' => Plan::where('is_active', true)->get(['id', 'name', 'price']),
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function store(Request $request, TenantProvisioner $provisioner)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'cuit' => ['nullable', 'string', 'max:20'],
            'plan_id' => ['required', 'exists:plans,id'],
            'status' => ['required', Rule::in(['trial', 'active'])],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);
        $provisioner->provision(
            ['name' => $data['name'], 'cuit' => $data['cuit'] ?? null, 'status' => $data['status']],
            ['name' => $data['admin_name'], 'email' => $data['admin_email'], 'password' => $data['admin_password']],
            Plan::find($data['plan_id']),
        );

        return back()->with('success', 'Empresa creada.');
    }

    public function update(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'status' => ['required', Rule::in(['trial', 'active', 'suspended', 'cancelled'])],
            'trial_ends_at' => ['nullable', 'date'],
            'subscription_ends_at' => ['nullable', 'date'],
        ]);
        $tenant->update($data);

        return back()->with('success', 'Empresa actualizada.');
    }
}
