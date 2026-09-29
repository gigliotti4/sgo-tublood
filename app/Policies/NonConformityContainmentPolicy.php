<?php

namespace App\Policies;

use App\Models\NonConformityContainment;
use App\Models\User;
use App\Policies\Concerns\GestionaRenglonesDeNc;

/**
 * Quién puede tocar UNA acción de contención (sección 3 del Informe de Desvío).
 *
 * Misma regla que las acciones del plan, en su propia clase porque Laravel las
 * descubre por el nombre del modelo. Lo que decide vive en
 * `GestionaRenglonesDeNc`, compartido por las dos.
 *
 * ⚠️ Es la razón por la que la sección 3 dejó de guardarse como una lista
 * entera: si el responsable de una fila pudiera mandar todo el array, estaría
 * reescribiendo las filas de los demás.
 */
class NonConformityContainmentPolicy
{
    use GestionaRenglonesDeNc;

    public function gestionar(User $user, NonConformityContainment $contencion): bool
    {
        return $this->puedeGestionarRenglon(
            $user,
            $contencion->noConformidad,
            $contencion->responsable_id,
        ) || $this->antesDeAprobar($user, $contencion);
    }

    /**
     * Borrar la fila. Como en el plan de acción, **no** se abre al responsable
     * del renglón: quien planteó la contención es quien la saca.
     */
    public function eliminar(User $user, NonConformityContainment $contencion): bool
    {
        $nc = $contencion->noConformidad;

        if (! $nc || $nc->trashed() || $nc->estaFinalizada()) {
            return false;
        }

        return $nc->responsable_id === $user->id
            || $user->can('nc.gestionar')
            || $this->antesDeAprobar($user, $contencion);
    }

    /**
     * Quien creó el desvío maneja la contención mientras no esté aprobado.
     *
     * ⚠️ **Vive acá y no en `GestionaRenglonesDeNc`**, aunque el trait sea el
     * lugar obvio: ese trait lo comparten la contención y el plan de acción, y
     * meterla ahí le abriría al creador los renglones del plan también. Hoy
     * sería inerte —un plan no existe en borrador— pero es una regla más ancha
     * que la que se pidió, escrita justo donde nadie la va a volver a mirar.
     * La excepción es de la sección 3, así que se queda en la Policy de la
     * sección 3. La condición la decide `NoConformidadPolicy::cargarContencion()`,
     * que es donde está explicada.
     */
    private function antesDeAprobar(User $user, NonConformityContainment $contencion): bool
    {
        $nc = $contencion->noConformidad;

        return $nc !== null && $user->can('cargarContencion', $nc);
    }
}
