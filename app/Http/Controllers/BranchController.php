<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Support\BusinessException;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BranchController extends Controller
{
    public function switch(Request $request)
    {
        $data = $request->validate(['branch_id' => ['required', 'integer']]);
        $branch = $request->user()->accessibleBranches()->firstWhere('id', $data['branch_id']);
        abort_unless($branch, 403);
        $request->user()->forceFill(['current_branch_id' => $branch->id])->saveQuietly();

        return back()->with('success', "Trabajando en: {$branch->name}");
    }

    public function index()
    {
        return Inertia::render('Settings/Branches', [
            'branches' => Branch::with('cashRegisters')->withCount('users')->orderByDesc('is_main')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, TenantContext $ctx)
    {
        $data = $this->validated($request);
        $plan = $ctx->tenant->plan;
        if ($plan && Branch::count() >= $plan->max_branches) {
            throw new BusinessException("Su plan ({$plan->name}) permite hasta {$plan->max_branches} sucursales/depósitos. Actualice el plan para agregar más.");
        }
        $branch = Branch::create($data);
        if ($branch->type === 'store') {
            CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja 1']);
        }

        return back()->with('success', $branch->type === 'warehouse' ? 'Depósito creado.' : 'Sucursal creada.');
    }

    public function update(Request $request, Branch $branch)
    {
        $data = $this->validated($request);
        if (! empty($data['is_main'])) {
            Branch::where('id', '!=', $branch->id)->update(['is_main' => false]);
        }
        $branch->update($data);

        return back()->with('success', 'Sucursal actualizada.');
    }

    public function storeRegister(Request $request, Branch $branch)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        CashRegister::create(['branch_id' => $branch->id, 'name' => $data['name']]);

        return back()->with('success', 'Caja creada.');
    }

    public function updateRegister(Request $request, CashRegister $register)
    {
        $register->update($request->validate(['name' => ['required', 'string', 'max:60'], 'is_active' => ['boolean']]));

        return back()->with('success', 'Caja actualizada.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:10'],
            'type' => ['required', Rule::in(['store', 'warehouse'])],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_main' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
