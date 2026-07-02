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
                ->with(['responsable:id,name', 'cliente:id,numero,razon_social,mail,telefono'])
                ->latest()
                ->paginate(20),
            'usuarios' => $request->user()->can('observaciones.edit')
                ? User::orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function update(Request $request, Observacion $observacion)
    {
        $this->authorize('observaciones.edit');

        $data = $request->validate([
            'responsable_id' => ['nullable', 'exists:users,id'],
            'estado' => ['required', 'in:'.implode(',', array_keys(Observacion::ESTADOS))],
        ]);

        $observacion->update($data);

        return redirect()->route('observaciones.index')
            ->with('success', 'Observación actualizada correctamente.');
    }
}
