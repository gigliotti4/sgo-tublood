<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Observacion;
use App\Models\User;
use Illuminate\Http\Request;

class ObservacionController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('observaciones.view');

        return inertia('Admin/Observaciones/Index', [
            'observaciones' => Observacion::query()
                ->with(['responsable:id,name', 'cliente:id,numero,razon_social,mail,telefono', 'productos'])
                ->latest()
                ->paginate(20),
            // Cualquiera que vea el listado puede necesitar reasignar responsable
            // en las filas que sí puede editar (ver ObservacionPolicy::update).
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Observacion $observacion)
    {
        $this->authorize('update', $observacion);

        $data = $request->validate([
            'responsable_id' => ['nullable', 'exists:users,id'],
            'estado' => ['required', 'in:'.implode(',', array_keys(Observacion::ESTADOS))],
        ]);

        $observacion->update($data);

        return redirect()->route('observaciones.index')
            ->with('success', 'Observación actualizada correctamente.');
    }
}
