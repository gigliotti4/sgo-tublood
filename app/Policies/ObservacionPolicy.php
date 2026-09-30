<?php

namespace App\Policies;

use App\Models\Observacion;
use App\Models\User;

class ObservacionPolicy
{
    /**
     * Puede gestionar el caso (reasignar, reclasificar, cambiar estado, subir
     * o borrar archivos sueltos, comentar en la bitácora) **solo** el
     * responsable asignado. Es la única habilidad de la Policy: hasta acá
     * también existía `comentar`, más amplia (sumaba a los notificados y a
     * cualquiera del sector), pero se decidió que gestionar un caso —
     * incluido dejar constancia en su bitácora— quede exclusivamente en
     * manos de quien lo tiene asignado. Notificados y gente del mismo sector
     * pasan a solo lectura.
     *
     * ⚠️ Consecuencia: una observación **sin responsable** (recién entrada
     * por el portal cuando nadie tiene el rol del tipo, o a la que se le
     * quitó el responsable) solo la puede tocar un super-admin, vía
     * `Gate::before`. Antes la regla del sector destrababa este caso — al
     * derivar, el responsable anterior queda liberado y cualquiera del
     * sector destino podía autoasignarse. Queda anotado para revisar cuando
     * se implemente la derivación entre sectores.
     */
    public function update(User $user, Observacion $observacion): bool
    {
        // Una observación borrada solo se gestiona desde la papelera
        // (restaurar), no desde el flujo normal de edición/gestión del caso.
        if ($observacion->trashed()) {
            return false;
        }

        return $user->id === $observacion->responsable_id;
    }

    /**
     * Corregir el **texto** del reclamo: título y descripción.
     *
     * Devuelve `false` para todos a propósito: el único que pasa es el
     * super-admin, por el bypass de `Gate::before`. Es el mismo molde que
     * `NoConformidadPolicy::reabrir()` y `::cancelar()`, y por el mismo motivo
     * — que no parezca parte del flujo normal de gestión del caso.
     *
     * ⚠️ **No es lo mismo que `update()`**, que es gestionar el caso (reasignar,
     * reclasificar, cambiar estado) y sí tiene el responsable. Acá se reescribe
     * lo que reportó el cliente, que en un sistema de calidad es evidencia: por
     * eso el cambio queda en la bitácora con el valor original entero, y por
     * eso la habilidad es más angosta que la de gestión.
     *
     * ⚠️ A diferencia de `update()`, acá no se chequea `trashed()`: como el
     * método devuelve `false` para todos y el único que entra es el super-admin
     * por `Gate::before`, cualquier condición que se escriba se saltea igual.
     * En los hechos, un super-admin puede corregir el texto de una observación
     * borrada — igual que puede hacer el resto de las cosas sobre una.
     */
    public function editarContenido(User $user, Observacion $observacion): bool
    {
        return false;
    }
}
