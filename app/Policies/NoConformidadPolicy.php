<?php

namespace App\Policies;

use App\Models\NoConformidad;
use App\Models\User;

/**
 * Autorización por objeto de una No Conformidad — §6 del instructivo.
 *
 * Se auto-descubre por convención de nombre (`NoConformidad` →
 * `NoConformidadPolicy`): no hay que registrarla en ningún provider.
 *
 * `super-admin` saltea todo esto vía `Gate::before()` en `AppServiceProvider`,
 * así que ninguna habilidad necesita contemplarlo — por eso `reabrir()` y
 * `cancelar()` devuelven `false` para todo el mundo: son exclusivas de
 * super-admin y el bypass es el que las habilita.
 */
class NoConformidadPolicy
{
    /**
     * Editar los datos de la NC.
     *
     * En Borrador la edita **su creador** (§6: "Editar la NC mientras se
     * encuentre en Borrador" / "Corregirla cuando haya sido devuelta" — una NC
     * devuelta vuelve justamente a Borrador).
     *
     * Ya aprobada, la edita Gestión de Calidad **o el responsable del caso**,
     * por el mismo criterio que `gestionar()`: desde la delegación del
     * 25/9/2026 el responsable lleva el caso de punta a punta, y dejarlo sin
     * poder corregir la cabecera —el sector, el motivo, la descripción— era una
     * incoherencia que obligaba a molestar a Calidad para un typo.
     *
     * ⚠️ Una NC cerrada queda **bloqueada para edición** (§4.8). Solo se
     * reabre, y eso es otra habilidad.
     */
    public function update(User $user, NoConformidad $nc): bool
    {
        if ($nc->trashed() || $nc->estaFinalizada()) {
            return false;
        }

        if ($nc->estado === 'borrador') {
            return $user->id === $nc->creado_por;
        }

        return $this->gestionar($user, $nc);
    }

    /**
     * Aprobar, devolver o rechazar la apertura (§4.3).
     *
     * El instructivo nombra a dos personas (Emanuel Durán o Facundo Durán). Se
     * modela como permiso y no como rol ni como ids hardcodeados: el seeder se
     * lo da a esos dos usuarios por email, y el día que cambie la gente es una
     * línea del seeder, no un `if` perdido en el código.
     */
    public function aprobar(User $user, NoConformidad $nc): bool
    {
        return $nc->estado === 'pendiente_aprobacion' && $user->can('nc.aprobar');
    }

    /**
     * Gestionar el caso: investigación, contención, causa raíz, plan de acción,
     * verificación, mover de etapa y cerrar.
     *
     * ⚠️ **No es solo Garantía de Calidad.** Hasta el 25/9/2026 alcanzaba con
     * `nc.gestionar` y nada más, y eso obligaba a que Calidad interviniera en la
     * operación de todos los casos. El cliente lo corrigió: Calidad hace
     * seguimiento y control, pero **no es responsable de todas las NC**. Por eso
     * el **responsable del caso** gestiona sin tener el permiso global, igual
     * que en `ObservacionPolicy`.
     *
     * Calidad conserva el acceso a todos los casos, que es lo que le permite
     * destrabar uno cuyo responsable se fue de licencia.
     */
    public function gestionar(User $user, NoConformidad $nc): bool
    {
        return ! $nc->trashed()
            && ! $nc->estaFinalizada()
            && ($user->can('nc.gestionar') || $nc->responsable_id === $user->id);
    }

    /**
     * Cargar la acción inmediata (sección 3) — la única sección que se escribe
     * **antes** de que el desvío esté aprobado.
     *
     * Invierte el orden que tenía el módulo, y es a propósito: cuando se
     * detecta un desvío lo primero que pasa en la planta es contenerlo
     * —segregar el producto, frenar la línea— y el papelerío viene después.
     * Quien aprueba además necesita leer qué se hizo para poder decidir.
     *
     * ⚠️ Por eso suma a **quien creó el desvío**: un desvío lo carga cualquier
     * usuario (`nc.create`, §6), y en borrador esa persona no es responsable
     * del caso ni tiene `nc.gestionar`. Con `gestionar()` a secas no podría
     * escribir la contención que ella misma acaba de tomar, que es justo lo
     * que se pidió el 29/9/2026.
     *
     * Los **dos** estados previos a la aprobación, no solo `borrador`: mientras
     * espera el visto bueno la medida puede cambiar, y una devolución la manda
     * de vuelta a borrador igual. Aprobada, vuelve a regir la regla normal.
     */
    public function cargarContencion(User $user, NoConformidad $nc): bool
    {
        if ($this->gestionar($user, $nc)) {
            return true;
        }

        return ! $nc->trashed()
            && in_array($nc->estado, ['borrador', 'pendiente_aprobacion'], true)
            && $user->id === $nc->creado_por;
    }

    /**
     * Designar o cambiar el responsable del caso.
     *
     * ⚠️ **El responsable actual puede pasar la posta sin pasar por Calidad**:
     * es un pedido explícito del cliente (25/9/2026). Sin eso, cada vez que
     * alguien se va de vacaciones o el caso cambia de mano habría que
     * molestar a Calidad, que es justamente el cuello de botella que se está
     * sacando.
     *
     * Quien aprueba también puede, pero **solo mientras decide la apertura**:
     * es el momento natural para elegir quién se hace cargo, y después deja de
     * ser asunto suyo.
     */
    public function asignarResponsable(User $user, NoConformidad $nc): bool
    {
        return ! $nc->trashed()
            && ! $nc->estaFinalizada()
            && ($user->can('nc.gestionar')
                || $nc->responsable_id === $user->id
                || ($nc->estado === 'pendiente_aprobacion' && $user->can('nc.aprobar')));
    }

    /**
     * Mandar la NC a aprobación (§3). Lo hace el creador desde su Borrador.
     *
     * Gestión de Calidad también puede, para destrabar un borrador de alguien
     * que se fue de licencia sin mandarlo — es el mismo trámite, no una
     * decisión sobre el contenido.
     */
    public function enviarAAprobacion(User $user, NoConformidad $nc): bool
    {
        return $nc->estado === 'borrador'
            && ($user->id === $nc->creado_por || $user->can('nc.gestionar'));
    }

    /**
     * Reabrir una NC cerrada (§4.8) y cancelarla (§6): **solo super-admin**,
     * vía el bypass de `Gate::before`.
     *
     * Devolver `false` acá es lo correcto, no un olvido: nadie más las tiene.
     * Lo único que se chequea es que la NC esté en un estado donde la acción
     * tenga sentido — el super-admin saltea el permiso, no la lógica.
     */
    public function reabrir(User $user, NoConformidad $nc): bool
    {
        return false;
    }

    public function cancelar(User $user, NoConformidad $nc): bool
    {
        return false;
    }
}
