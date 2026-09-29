<?php

namespace App\Notifications;

use App\Models\NoConformidad;
use Illuminate\Support\Str;

/**
 * Una acción del plan se pasó de su fecha prevista.
 *
 * La manda `nc:recordatorios`, que es también quien escribe el estado
 * `vencida`. ⚠️ Ese cambio de estado **es** la marca de idempotencia: como
 * `vencida` sale del filtro de la tarea, la acción no vuelve a avisar por más
 * que siga atrasada. Mismo truco que `alerta_nivel` en observaciones.
 *
 * Recibe la descripción como texto y no el modelo, igual que
 * `AccionAsignadaNotification`: lo que hace falta para redactar el aviso es qué
 * había que hacer y para cuándo, no la fila entera.
 */
class AccionVencidaNotification extends NoConformidadNotification
{
    public function __construct(
        NoConformidad $noConformidad,
        public string $descripcion,
        public string $fechaPrevista,
    ) {
        parent::__construct($noConformidad);
    }

    protected function tipo(): string
    {
        return 'nc_accion_vencida';
    }

    protected function asunto(): string
    {
        $numero = $this->noConformidad->numero;

        return $numero
            ? "Se venció una acción de la No Conformidad {$numero}"
            : 'Se venció una acción de una No Conformidad';
    }

    protected function mensaje(): string
    {
        // Se recorta por el mismo motivo que en AccionAsignadaNotification: el
        // texto es la primera línea del mail y el cuerpo de la campana.
        return 'Se pasó de fecha: '.Str::limit(trim($this->descripcion), 180);
    }

    /**
     * ⚠️ El texto **no acusa a nadie**, mismo criterio que los avisos de
     * vencimiento de observaciones: el atraso puede no ser culpa de quien tiene
     * la acción, y el mail le llega justamente a esa persona.
     *
     * @return array<int, string>
     */
    protected function detalles(object $notifiable): array
    {
        return [
            "La fecha prevista era el {$this->fechaPrevista}.",
            'Si ya está hecha, registrá el avance desde la ficha. Si dejó de tener sentido, se puede cancelar explicando por qué.',
        ];
    }
}
