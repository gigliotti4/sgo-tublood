<?php

namespace App\Models\Concerns;

use App\Support\Documentacion;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Clasificación documental de un registro: su tipo (el del catálogo de
 * config/documentacion.php), el checklist de documentos que ese tipo exige, y
 * los dos campos derivados que salen de cruzarlos.
 *
 * Vive en un trait y no en el modelo porque es la misma lógica para cualquier
 * registro que se clasifique: lo único propio de cada uno es de qué columna
 * sale el tipo y qué modelo guarda su checklist.
 *
 * Quien lo use tiene que declarar además, en su tabla:
 *   fecha_vencimiento       (date, nullable)  — derivado
 *   documentacion_completa  (boolean)         — derivado, denormalizado para
 *                                               poder filtrar el listado en SQL
 */
trait ClasificacionDocumental
{
    /** Modelo de la tabla hija que guarda el checklist. */
    abstract protected function modeloDocumento(): string;

    /** Columna donde vive el tipo (ej. `tipo_cliente`). */
    abstract public function tipoDocumental(): ?string;

    /** Checklist de documentación. Ver App\Support\Documentacion. */
    public function documentos(): HasMany
    {
        return $this->hasMany($this->modeloDocumento());
    }

    /**
     * Estado documental calculado: el "Control automático" de la planilla.
     *
     * Se calcula siempre desde el catálogo del tipo cruzado con lo cargado, y
     * no desde las filas de la tabla hija solas: si un documento deja de
     * pertenecer al tipo, o si el tipo suma uno nuevo, el estado tiene que
     * reflejarlo sin esperar a que alguien vuelva a guardar el checklist.
     *
     * "Completa" exige que estén todos los obligatorios presentados **y** que
     * ninguno esté vencido; `faltantes` y `vencidos` dicen por cuál de las dos
     * cosas no lo está.
     *
     * @return array{
     *     completa: bool,
     *     faltantes: list<string>,
     *     vencidos: list<array{documento: string, label: string, fecha_vencimiento: string}>,
     *     proximo_vencimiento: string|null,
     *     dias_para_vencer: int|null
     * }
     */
    public function estadoDocumentacion(): array
    {
        $tipo = $this->tipoDocumental();
        $catalogo = Documentacion::documentos($tipo);
        $cargados = $this->documentos->keyBy('documento');
        $hoy = Carbon::today();

        $faltantes = [];
        $vencidos = [];
        $proximo = null;

        foreach ($catalogo as $clave => $def) {
            $doc = $cargados->get($clave);
            $presentado = (bool) $doc?->presentado;

            if (! $presentado) {
                if ($def['obligatorio']) {
                    $faltantes[] = $def['label'];
                }

                // Un documento sin presentar no vence ni cuenta para el próximo
                // vencimiento: la fecha que pudiera tener cargada es del papel
                // que todavía no entregaron.
                continue;
            }

            $fecha = empty($def['vence']) ? null : $doc?->fecha_vencimiento;

            if ($fecha === null) {
                continue;
            }

            if ($fecha->lt($hoy)) {
                $vencidos[] = [
                    'documento' => $clave,
                    'label' => $def['label'],
                    'fecha_vencimiento' => $fecha->toDateString(),
                ];
            }

            if ($proximo === null || $fecha->lt($proximo)) {
                $proximo = $fecha;
            }
        }

        return [
            // Sin tipo no hay nada que cumplir, pero tampoco está en
            // condiciones: no se sabe qué debería presentar.
            'completa' => $tipo !== null && $faltantes === [] && $vencidos === [],
            'faltantes' => $faltantes,
            'vencidos' => $vencidos,
            'proximo_vencimiento' => $proximo?->toDateString(),
            'dias_para_vencer' => $proximo === null ? null : (int) $hoy->diffInDays($proximo, false),
        ];
    }

    /**
     * Recalcula y guarda los dos campos derivados: el vencimiento (el del
     * documento que lo determina, ver el catálogo) y el booleano de
     * documentación completa que usa el filtro del listado.
     *
     * Se llama desde el controller y no desde un observer sobre la tabla hija
     * porque el estado depende también del tipo, que se guarda por otro camino:
     * un observer sobre el hijo no se enteraría de que cambiar el tipo cambia
     * qué documento manda. Mismo criterio que
     * ObservacionController::sincronizarNotificados().
     */
    public function recalcularEstadoDocumental(): void
    {
        $this->load('documentos');

        $determinante = Documentacion::documentoDeterminante($this->tipoDocumental());

        $doc = $determinante === null
            ? null
            : $this->documentos->firstWhere('documento', $determinante);

        $this->update([
            'fecha_vencimiento' => $doc?->presentado ? $doc->fecha_vencimiento : null,
            'documentacion_completa' => $this->estadoDocumentacion()['completa'],
        ]);
    }
}
