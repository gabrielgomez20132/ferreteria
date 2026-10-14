<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\BusinessException;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class UserController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    public function index()
    {
        return Inertia::render('Settings/Users', [
            'users' => User::where('tenant_id', $this->ctx->id())->with('role:id,name', 'branches:id,name')->orderBy('name')->get(),
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'branches' => Branch::where('is_active', true)->get(['id', 'name']),
            'plan' => $this->ctx->tenant->plan?->only(['name', 'max_users']),
        ]);
    }

    private function rules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', Password::min(8)],
            'role_id' => ['required', Rule::exists('roles', 'id')->where('tenant_id', $this->ctx->id())],
            'is_active' => ['boolean'],
            'branch_ids' => ['array'],
            'branch_ids.*' => [Rule::exists('branches', 'id')->where('tenant_id', $this->ctx->id())],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $plan = $this->ctx->tenant->plan;
        if ($plan && User::where('tenant_id', $this->ctx->id())->where('is_active', true)->count() >= $plan->max_users) {
            throw new BusinessException("Su plan ({$plan->name}) permite hasta {$plan->max_users} usuarios activos.");
        }
        $user = User::create([
            'tenant_id' => $this->ctx->id(),
            'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
            'role_id' => $data['role_id'], 'is_active' => $data['is_active'] ?? true,
            'current_branch_id' => $data['branch_ids'][0] ?? $this->ctx->branchId(),
        ]);
        $user->branches()->sync($data['branch_ids'] ?? []);

        return back()->with('success', 'Usuario creado.');
    }

    public function update(Request $request, User $user)
    {
        abort_unless($user->tenant_id === $this->ctx->id(), 404);
        $data = $request->validate($this->rules($user));
        if ($user->id === $request->user()->id && empty($data['is_active'])) {
            throw new BusinessException('No puede desactivar su propio usuario.');
        }
        $attrs = collect($data)->only(['name', 'email', 'role_id', 'is_active'])->all();
        if (! empty($data['password'])) {
            $attrs['password'] = $data['password'];
        }
        $user->update($attrs);
        $user->branches()->sync($data['branch_ids'] ?? []);

        return back()->with('success', 'Usuario actualizado.');
    }
}
