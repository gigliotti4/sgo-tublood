<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Permisos con su etiqueta en español y su grupo, para las tres pantallas
     * que los listan. Los que no estén en config/permisos.php caen a su nombre
     * técnico y al grupo "Otros", así que un permiso nuevo nunca deja la
     * pantalla en blanco.
     */
    private function permisosConEtiqueta()
    {
        $etiquetas = config('permisos.etiquetas');
        $grupos = config('permisos.grupos');

        return Permission::orderBy('name')->get(['id', 'name'])
            ->map(function (Permission $permission) use ($etiquetas, $grupos) {
                $meta = $etiquetas[$permission->name] ?? null;

                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'label' => $meta['label'] ?? $permission->name,
                    'grupo' => $grupos[$meta['grupo'] ?? ''] ?? 'Otros',
                ];
            });
    }

    public function index()
    {
        $this->authorize('roles.view');

        return inertia('Admin/Roles/Index', [
            'roles' => Role::with('permissions')->paginate(15),
            'permisos' => $this->permisosConEtiqueta(),
        ]);
    }

    public function create()
    {
        $this->authorize('roles.create');

        return inertia('Admin/Roles/Create', [
            'permissions' => $this->permisosConEtiqueta(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('roles.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['exists:permissions,name'],
        ]);

        $role = Role::create(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()->route('roles.index')
            ->with('success', 'Rol creado correctamente.');
    }

    public function edit(Role $role)
    {
        $this->authorize('roles.edit');

        return inertia('Admin/Roles/Edit', [
            'role' => $role->load('permissions'),
            'permissions' => $this->permisosConEtiqueta(),
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $this->authorize('roles.edit');

        $data = $request->validate([
            'name' => ['required', 'string', "unique:roles,name,{$role->id}"],
            'permissions' => ['array'],
            'permissions.*' => ['exists:permissions,name'],
        ]);

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()->route('roles.index')
            ->with('success', 'Rol actualizado correctamente.');
    }

    public function destroy(Role $role)
    {
        $this->authorize('roles.delete');

        if ($role->name === 'super-admin') {
            return back()->with('error', 'No se puede eliminar el rol super-admin.');
        }

        $role->delete();

        return redirect()->route('roles.index')
            ->with('success', 'Rol eliminado correctamente.');
    }
}
