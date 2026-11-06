<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ClienteApiResource;
use App\Http\Resources\Api\ObservacionApiResource;
use App\Models\Cliente;
use App\Models\Observacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Consulta de solo lectura para sistemas externos. La autenticación y el
 * permiso (`abilities:observaciones:leer`) los pone `routes/api.php`; por eso
 * acá no hay `authorize()`, igual que en `Portal/`.
 *
 * "Abierta" es la definición de siempre (`Observacion::ESTADOS_ABIERTOS`, con
 * `derivada_nc` incluida). Las borradas quedan afuera solas por el soft delete.
 *
 * ⚠️ Se cruza por `observations.cliente_id`: una observación del portal cuyo
 * número de cliente no matcheó ninguno local no tiene cliente y no aparece.
 */
class ObservacionesAbiertasController extends Controller
{
    public function clientes()
    {
        $abiertas = fn (Builder $q) => $q->whereIn('estado', Observacion::ESTADOS_ABIERTOS);

        return ClienteApiResource::collection(
            Cliente::query()
                ->select(['id', 'numero', 'razon_social'])
                ->whereHas('observaciones', $abiertas)
                ->withCount(['observaciones' => $abiertas])
                ->orderBy('numero')
                ->paginate(100)
        );
    }

    public function observaciones(string $numero): JsonResponse
    {
        $cliente = Cliente::query()
            ->select(['id', 'numero', 'razon_social'])
            ->where('numero', $numero)
            ->first();

        if (! $cliente) {
            return response()->json(['message' => 'Cliente no encontrado.'], 404);
        }

        $observaciones = $cliente->observaciones()
            ->whereIn('estado', Observacion::ESTADOS_ABIERTOS)
            ->orderBy('id')
            ->get(['id', 'numero', 'tipo', 'estado', 'prioridad', 'titulo', 'vence_at', 'prorroga_hasta', 'created_at']);

        return response()->json([
            'data' => [
                'cliente' => (new ClienteApiResource($cliente))->resolve(),
                'observaciones' => ObservacionApiResource::collection($observaciones)->resolve(),
            ],
        ]);
    }
}
