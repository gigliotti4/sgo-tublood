<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\OrdenaListados;
use App\Http\Controllers\Concerns\VuelveAlListado;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    use OrdenaListados, VuelveAlListado;

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

    /**
     * @return array<string, string|Closure>
     */
    private function ordenables(): array
    {
        return [
            'nombre' => 'name',
            'permisos' => fn (Builder $q, string $dir) => $q
                ->withCount('permissions')
                ->orderBy('permissions_count', $dir),
        ];
    }

    public function index(Request $request)
    {
        $this->authorize('roles.view');

        return inertia('Admin/Roles/Index', [
            // El orden por defecto es nuevo: este listado paginaba **sin
            // ninguno**, y eso es no determinista — podía repetir filas entre
            // páginas. `withQueryString()` también, para no perder el orden al
            // pasar de página.
            'roles' => $this->aplicarOrden(
                Role::with('permissions'),
                $request,
                $this->ordenables(),
                fn (Builder $q) => $q->orderBy('name'),
                'roles.id',
            )->paginate(15)->withQueryString(),
            'orden' => $this->orden($request, $this->ordenables()),
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

        // Al listado, pero **con los filtros y la página** que traía: ver el
        // trait `VuelveAlListado`.
        return $this->alListado($request, 'roles.index')
            ->with('success', 'Rol actualizado correctamente.');
    }

    public function destroy(Role $role)
    {
        $this->authorize('roles.delete');

        if ($role->name === 'super-admin') {
            return back()->with('error', 'No se puede eliminar el rol super-admin.');
        }

        $role->delete();

        // `back()`, igual que la rama de error de arriba: el borrado se
        // confirma en un modal del listado.
        return back(fallback: route('roles.index'))
            ->with('success', 'Rol eliminado correctamente.');
    }
}
