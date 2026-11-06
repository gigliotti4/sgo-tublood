<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Personal por sector, con o sin usuario del SGO (pedido del 6/11/2026).
 *
 * En Logística, Depósito y Calidad hay gente —choferes, operarios, personal de
 * laboratorio— que no usa el sistema y por eso no aparece en ninguna lista. Acá
 * se la carga (nombre, sector, puesto, activo) sin crearle usuario ni
 * contraseña, y alimenta el desplegable "Responsable del fallo".
 *
 * ⚠️ **No hay borrado**: quien deja la empresa se marca inactivo. No se ofrece
 * más para elegir, pero sigue figurando en las observaciones que ya tenía; un
 * borrado se llevaría el nombre del historial (la FK es `nullOnDelete`).
 *
 * Se gatea con los permisos de usuarios, como Sectores: es parte de la misma
 * estructura de personas.
 */
class PersonaController extends Controller
{
    public function index()
    {
        $this->authorize('users.view');

        return inertia('Admin/Personas/Index', [
            // Son cientos como mucho y el listado filtra y ordena en el cliente,
            // como Sectores: un roundtrip por click recargaría algo que ya está
            // en memoria.
            'personas' => Persona::with(['sector:id,nombre', 'usuario:id,name,apellido'])
                ->withCount('observacionesConFallo')
                ->orderBy('nombre')
                ->get(),
            'sectores' => Sector::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            // Para unir a la persona con su usuario cuando lo tiene. Sin `password`.
            'usuarios' => User::orderBy('name')->get(['id', 'name', 'apellido']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('users.edit');

        Persona::create($this->validar($request));

        // `back()`: el alta es un modal sobre el propio listado (como Sectores).
        return back(fallback: route('personas.index'))
            ->with('success', 'Persona cargada correctamente.');
    }

    public function update(Request $request, Persona $persona)
    {
        $this->authorize('users.edit');

        $persona->update($this->validar($request));

        return back(fallback: route('personas.index'))
            ->with('success', 'Persona actualizada correctamente.');
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'sector_id' => ['required', 'exists:sectors,id'],
            'puesto' => ['nullable', 'string', 'max:255'],
            'activo' => ['boolean'],
            'user_id' => ['nullable', Rule::exists('users', 'id')],
        ], [], [
            'nombre' => 'nombre',
            'sector_id' => 'sector',
        ]);
    }
}
