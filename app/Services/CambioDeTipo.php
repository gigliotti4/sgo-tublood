<?php

namespace App\Services;

use App\Models\Observacion;
use App\Models\Sector;
use App\Support\TaxonomiaIncidencias;
use Illuminate\Validation\ValidationException;

/**
 * Reencuadra una observación en otro tipo de incidencia.
 *
 * Vive en un servicio y no en el controller porque lo usan dos caminos —el
 * modal de edición (`ObservacionController::cambiarTipo()`) y la reclasificación
 * masiva de casos existentes (`observaciones:reclasificar`)— y las reglas tienen
 * que ser las mismas: dos copias se desincronizan.
 *
 * Qué cambia y qué no:
 * - Cambian `tipo`, `sector_id` y `datos_especificos`. Los datos del tipo viejo
 *   los guarda el observer en la bitácora (acción `tipo`, `datos_anteriores`).
 * - **No** cambian `origen` (externa/interna) ni `tipo_caso`: una observación
 *   que entró como reclamo de cliente sigue figurando como tal aunque el fallo
 *   haya sido de otro sector.
 * - Los productos ya cargados no se tocan.
 * - `observations.sector_id` no mueve el plazo (el vencimiento sale del sector
 *   del responsable): ver "Sectores" en CLAUDE.md.
 *
 * Desde el 6/11/2026 se puede cruzar entre reclamos de cliente (tipos
 * `especial`) e incidencias internas, en los dos sentidos. Hasta entonces el
 * reclamo quedaba atrapado en los dos tipos que el cliente eligió al cargarlo.
 */
class CambioDeTipo
{
    /**
     * Valida que el caso pueda pasar a ese tipo y devuelve si el destino es un
     * tipo especial. Tira `ValidationException` (clave `tipo`) si no.
     */
    public function comprobar(Observacion $observacion, Sector $sector, string $tipo): bool
    {
        if (! TaxonomiaIncidencias::tipo($sector->slug, $tipo)) {
            throw ValidationException::withMessages([
                'tipo' => 'El tipo de incidencia no corresponde al sector seleccionado.',
            ]);
        }

        if (TaxonomiaIncidencias::requiereCliente($sector->slug, $tipo) && blank($observacion->contacto_numero_cliente)) {
            throw ValidationException::withMessages([
                'tipo' => 'Ese tipo exige el N° de cliente, y esta observación no lo tiene cargado.',
            ]);
        }

        $eraEspecial = $this->esEspecial((string) $observacion->tipo);
        $esEspecial = TaxonomiaIncidencias::esTipoEspecial($sector->slug, $tipo);

        // Cruzar hacia un reclamo de cliente que exige productos sin tenerlos
        // dejaría una ficha vacía. Dentro de los especiales no se revisa: ya
        // era así y no es lo que se está cambiando.
        if ($esEspecial && ! $eraEspecial
            && TaxonomiaIncidencias::productos($tipo) === 'requeridos'
            && ! $observacion->productos()->exists()) {
            throw ValidationException::withMessages([
                'tipo' => 'Ese tipo exige productos afectados, y esta observación no tiene ninguno cargado.',
            ]);
        }

        return $esEspecial;
    }

    /**
     * Reglas para validar los "Datos específicos" del tipo destino con el mismo
     * criterio del alta (un cambio no puede dejar el caso en un estado que el
     * formulario original no habría aceptado). Vacías para los especiales.
     *
     * @return array{reglas: array<string, mixed>, atributos: array<string, string>}
     */
    public function reglasDeDatos(Sector $sector, string $tipo): array
    {
        return [
            'reglas' => TaxonomiaIncidencias::reglasValidacion($sector->slug, $tipo),
            'atributos' => TaxonomiaIncidencias::atributosValidacion($sector->slug, $tipo),
        ];
    }

    /**
     * Aplica el cambio. `$datos` ya viene validado.
     *
     * Entre dos especiales los datos se conservan tal cual. Al **entrar** a un
     * especial desde una incidencia interna se vacían: esos tipos no tienen
     * "Datos específicos" y lo anterior quedaría huérfano en el JSON (igual
     * queda entero en la bitácora).
     *
     * @param  array<string, mixed>|null  $datos
     */
    public function aplicar(Observacion $observacion, Sector $sector, string $tipo, ?array $datos): Observacion
    {
        $esEspecial = TaxonomiaIncidencias::esTipoEspecial($sector->slug, $tipo);
        $eraEspecial = $this->esEspecial((string) $observacion->tipo);

        $observacion->update([
            'tipo' => $tipo,
            'sector_id' => $sector->id,
            'datos_especificos' => match (true) {
                $esEspecial && $eraEspecial => $observacion->datos_especificos,
                $esEspecial => null,
                default => $datos ?? [],
            },
        ]);

        return $observacion;
    }

    private function esEspecial(string $tipo): bool
    {
        $sectorActual = TaxonomiaIncidencias::sectorDeTipo($tipo);

        return $sectorActual !== null && TaxonomiaIncidencias::esTipoEspecial($sectorActual, $tipo);
    }
}
