<?php

namespace App\Http\Resources\Api;

use App\Models\Observacion;
use App\Support\TaxonomiaIncidencias;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Whitelist explícita, igual que `ClienteApiResource`. A propósito NO viajan la
 * descripción, el contacto de quien reclamó ni el responsable interno: el
 * sistema externo recibe solo lo básico (decisión del 2/11/2026).
 *
 * @mixin Observacion
 */
class ObservacionApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'numero' => $this->numero,
            'tipo' => $this->tipo,
            'tipo_label' => TaxonomiaIncidencias::etiquetasTipos()[$this->tipo] ?? $this->tipo,
            'estado' => $this->estado,
            'estado_label' => Observacion::ESTADOS[$this->estado] ?? $this->estado,
            'prioridad' => $this->prioridad,
            'prioridad_label' => $this->prioridad
                ? (config('incidencias.prioridades')[$this->prioridad] ?? $this->prioridad)
                : null,
            'titulo' => $this->titulo,
            'creada_at' => $this->created_at?->toIso8601String(),
            'vence_at' => $this->vence_at?->toIso8601String(),
        ];
    }
}
