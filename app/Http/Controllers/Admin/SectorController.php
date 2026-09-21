<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sector;
use App\Services\UserImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ABM mínimo de los sectores. Existe para poder corregir el plazo de gestión
 * sin tener que reimportar el Excel; el alta masiva sigue siendo
 * {@see UserImportService}.
 *
 * El catálogo de sectores es fijo (los del `SectorSeeder`, que además definen
 * los tipos de incidencia en `config/incidencias.php`) — este ABM permite
 * editar nombre/plazo/activo, no reemplaza al seeder como fuente del catálogo.
 *
 * Se gatea con los permisos de usuarios porque es parte de la misma estructura.
 */
class SectorController extends Controller
{
    public function index()
    {
        $this->authorize('users.view');

        return inertia('Admin/Sectores/Index', [
            'sectores' => Sector::withCount('usuarios')->orderBy('nombre')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('users.edit');

        $data = $this->validar($request);

        Sector::create([...$data, 'slug' => Str::slug($data['nombre'])]);

        // `back()` aunque sea un `store()`: el alta es un modal sobre el propio
        // listado, no una página aparte, así que el usuario tiene que quedarse
        // donde estaba. La regla es de dónde sale la acción, no si crea o edita.
        return back(fallback: route('sectores.index'))
            ->with('success', 'Sector creado correctamente.');
    }

    public function update(Request $request, Sector $sector)
    {
        $this->authorize('users.edit');

        // El slug no se recalcula al renombrar: es la clave con la que el import
        // reconoce el sector y con la que `config/incidencias.php` define sus
        // tipos de incidencia — cambiarlo rompería esa referencia.
        $sector->update($this->validar($request, $sector));

        return back(fallback: route('sectores.index'))
            ->with('success', 'Sector actualizado correctamente.');
    }

    private function validar(Request $request, ?Sector $sector = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255', Rule::unique('sectors', 'nombre')->ignore($sector)],
            'dias_gestion' => ['nullable', 'integer', 'min:1', 'max:365'],
            'activo' => ['boolean'],
        ]);
    }
}
