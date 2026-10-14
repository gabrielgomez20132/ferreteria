<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PlanController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Plans', ['plans' => Plan::withCount('tenants')->orderBy('price')->get()]);
    }

    private function rules(?Plan $plan = null): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'slug' => ['required', 'alpha_dash', 'max:60', Rule::unique('plans')->ignore($plan?->id)],
            'price' => ['required', 'numeric', 'min:0'],
            'max_branches' => ['required', 'integer', 'min:1'],
            'max_users' => ['required', 'integer', 'min:1'],
            'max_products' => ['nullable', 'integer', 'min:1'],
            'features' => ['array'],
            'is_active' => ['boolean'],
        ];
    }

    public function store(Request $request)
    {
        Plan::create($request->validate($this->rules()));

        return back()->with('success', 'Plan creado.');
    }

    public function update(Request $request, Plan $plan)
    {
        $plan->update($request->validate($this->rules($plan)));

        return back()->with('success', 'Plan actualizado.');
    }
}
