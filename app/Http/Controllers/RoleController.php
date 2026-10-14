<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Support\BusinessException;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class RoleController extends Controller
{
    public function index()
    {
        return Inertia::render('Settings/Roles', [
            'roles' => Role::withCount('users')->orderBy('name')->get(),
            'groups' => Permissions::GROUPS,
        ]);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'max_discount' => ['required', 'numeric', 'min:0', 'max:100'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(array_merge(Permissions::all(), ['*']))],
        ];
    }

    public function store(Request $request)
    {
        Role::create($request->validate($this->rules()));

        return back()->with('success', 'Rol creado.');
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate($this->rules());
        if ($role->is_system) {
            $data['permissions'] = ['*']; // el rol Administrador siempre conserva todos los permisos
        }
        $role->update($data);

        return back()->with('success', 'Rol actualizado.');
    }

    public function destroy(Role $role)
    {
        if ($role->is_system || $role->users()->exists()) {
            throw new BusinessException('No se puede eliminar un rol del sistema o con usuarios asignados.');
        }
        $role->delete();

        return back()->with('success', 'Rol eliminado.');
    }
}
