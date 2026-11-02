<?php

namespace App\Http\Resources\Api;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Whitelist explícita de lo que la API le muestra al sistema externo: nunca
 * `toArray()` del modelo, que arrastraría mail, CUIT, domicilio y notas.
 *
 * @mixin Cliente
 */
class ClienteApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'numero' => $this->numero,
            'razon_social' => $this->razon_social,
            // Solo está cuando la consulta pidió `withCount` (el listado).
            'observaciones_abiertas' => $this->whenCounted('observaciones'),
        ];
    }
}
