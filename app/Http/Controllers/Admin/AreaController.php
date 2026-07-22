<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Services\UserImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ABM mínimo de las áreas del organigrama. Existe para poder corregir el plazo
 * de gestión sin tener que reimportar el Excel; el alta masiva sigue siendo
 * {@see UserImportService}.
 *
 * Se gatea con los permisos de usuarios porque es parte de la misma estructura.
 */
class AreaController extends Controller
{
    public function index()
    {
        $this->authorize('users.view');

        return inertia('Admin/Areas/Index', [
            'areas' => Area::withCount('usuarios')->orderBy('nombre')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('users.edit');

        $data = $this->validar($request);

        Area::create([...$data, 'slug' => Str::slug($data['nombre'])]);

        return redirect()->route('areas.index')
            ->with('success', 'Área creada correctamente.');
    }

    public function update(Request $request, Area $area)
    {
        $this->authorize('users.edit');

        // El slug no se recalcula al renombrar: es la clave con la que el import
        // reconoce el área, y cambiarlo haría que el próximo Excel la duplique.
        $area->update($this->validar($request, $area));

        return redirect()->route('areas.index')
            ->with('success', 'Área actualizada correctamente.');
    }

    private function validar(Request $request, ?Area $area = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255', Rule::unique('areas', 'nombre')->ignore($area)],
            'dias_gestion' => ['nullable', 'integer', 'min:1', 'max:365'],
            'activo' => ['boolean'],
        ]);
    }
}
