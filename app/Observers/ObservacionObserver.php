<?php

namespace App\Observers;

use App\Models\Observacion;
use App\Models\ObservationHistory;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\ObservacionAsignadaNotification;
use App\Notifications\ObservacionCreadaNotification;
use App\Notifications\ObservacionCriticaNotification;
use App\Notifications\ObservacionFinalizadaNotification;
use App\Support\TaxonomiaIncidencias;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Arranca y corta el reloj de gestión de una observación, y deja la entrada de
 * bitácora de cada cambio relevante.
 *
 * Vive en un observer y no en el controller porque el responsable (y el
 * sector, y la clasificación) se asignan desde varios lugares distintos
 * (`storeInterna`, `storeInternaEspecial` y `update` de Admin\ObservacionController,
 * más lo que sume la derivación el día que se implemente), y así queda un solo
 * punto de verdad tanto para el reloj como para la bitácora.
 */
class ObservacionObserver
{
    public function saving(Observacion $observacion): void
    {
        $this->marcarCierre($observacion);

        if (! $observacion->isDirty('responsable_id')) {
            return;
        }

        // Reasignar (o desasignar) reinicia el reloj: el plazo es de la persona
        // que tiene la observación ahora, no de la que la tenía antes.
        $observacion->responsable_asignado_at = null;
        $observacion->vence_at = null;
        $observacion->alerta_nivel = 0;

        if ($observacion->responsable_id === null) {
            return;
        }

        $dias = User::with('sector')->find($observacion->responsable_id)?->sector?->dias_gestion;

        $observacion->responsable_asignado_at = now();
        // Sin sector o sin plazo cargado no hay contra qué medir: la observación
        // queda sin vencimiento y nunca alerta.
        $observacion->vence_at = $dias ? now()->addWeekdays($dias) : null;
    }

    /**
     * Sella (o borra) la fecha de cierre cuando el caso entra o sale de un
     * estado final. Alimenta el KPI de tiempo promedio de resolucion.
     *
     * Va en `saving()` y no en `updated()` a proposito: aca el valor viaja en
     * el mismo UPDATE que el cambio de estado. Hacerlo en `updated()` obligaria
     * a un segundo guardado con `saveQuietly()` para no re-disparar el observer
     * en bucle.
     *
     * Reabrir un caso vuelve `cerrada_at` a null: sin esto, un caso reabierto y
     * cerrado de nuevo conservaria la fecha del primer cierre y mentiria el
     * promedio. Lo mismo vale para `cerrada_por`.
     */
    private function marcarCierre(Observacion $observacion): void
    {
        if (! $observacion->isDirty('estado')) {
            return;
        }

        $finalizada = $observacion->estaFinalizada();

        $observacion->cerrada_at = $finalizada ? now() : null;
        // Quién cerró, en la misma pasada y en columna propia: la bitácora
        // guarda la **etiqueta legible** del estado y ya se demostró que no es
        // consultable de forma confiable (ver la migración de `cerrada_at`,
        // que tuvo que buscar 'Cerrada' y 'Resuelta' para su backfill).
        //
        // Null cuando el cierre no lo hace una persona (un comando, un seeder):
        // es mejor que "Sistema" sea un caso explícito de la pantalla y no un
        // id inventado.
        $observacion->cerrada_por = $finalizada ? auth()->id() : null;
    }

    public function updated(Observacion $observacion): void
    {
        $this->registrarCambios($observacion);

        if ($observacion->wasChanged('responsable_id') && $observacion->responsable_id !== null) {
            User::find($observacion->responsable_id)?->notify(new ObservacionAsignadaNotification(
                $observacion,
                $observacion->getOriginal('responsable_id') !== null,
            ));
        }

        $this->avisarSiPasoACritica($observacion);

        if (! $observacion->wasChanged('estado') || ! $observacion->estaFinalizada()) {
            return;
        }

        $observacion->responsable?->gerente
            ?->notify(new ObservacionFinalizadaNotification($observacion));
    }

    public function created(Observacion $observacion): void
    {
        // El alta del portal asigna sola por tipo y avisa con "entró un reclamo
        // nuevo"; sumarle el de asignación sería contar el mismo hecho dos
        // veces. Ver `Observacion::$omitirAvisoDeAsignacion`.
        //
        // ⚠️ La bandera apaga **solo** el aviso de asignación y no el método
        // entero: el aviso a los super-admin de abajo es de otras personas y de
        // otro hecho (entró un caso al sistema), y nadie se lo mandó ya.
        if (! $observacion->omitirAvisoDeAsignacion) {
            User::find($observacion->responsable_id)?->notify(new ObservacionAsignadaNotification($observacion));
        }

        $this->avisarALosSuperAdmin($observacion);
    }

    /**
     * Aviso a los super-admin de cada alta, venga del portal o del panel.
     *
     * Vive en el observer y no en los controllers porque hay tres caminos de
     * alta (`Portal\ObservacionController::store`, `storeInterna` y
     * `storeInternaEspecial`), más la derivación el día que se implemente:
     * mismo criterio que el reloj de gestión y el aviso de asignación.
     *
     * Se excluyen tres conjuntos, con el mismo criterio que
     * `avisarSiPasoACritica()` — avisarle a alguien de algo que ya le llegó, o
     * de su propia acción, entrena a la gente a ignorar los mails:
     *
     * - Quien cargó el caso (`created_by`; `auth()->id()` como red para las
     *   altas que todavía no lo guardan). En el portal los dos son null.
     * - El responsable asignado: ya recibe `ObservacionAsignadaNotification`
     *   (interna) o `ObservacionExternaRecibidaNotification` (portal).
     * - En las externas, quien atiende ese tipo por rol
     *   (`incidencias.roles_por_tipo`). **No** se replica el *fallback* de
     *   `Portal\ObservacionController::avisar()` (todo el equipo de Calidad
     *   cuando nadie tiene el rol): es un estado de configuración incompleta,
     *   dura hasta que se asigne el rol, y duplicar esa regla acá la condenaba
     *   a desincronizarse del original. En ese caso puntual un super-admin de
     *   Calidad recibe dos mails, que es el precio correcto.
     *
     * ⚠️ Dos cosas no negociables, porque esto corre también en el alta del
     * portal, que es pública y **nunca puede perder un reclamo**:
     *
     * - Se filtra con `whereHas('roles', ...)` y **no** con el scope
     *   `User::role(...)` de Spatie, que tira `RoleDoesNotExist` si el rol no
     *   está creado — un 500 en un endpoint público.
     * - Todo va en un `try/catch` que loguea: el evento `created` dispara
     *   **dentro** de la transacción de `Observacion::altaConNumero()`, así que
     *   una excepción acá tiraría abajo el alta entera.
     */
    private function avisarALosSuperAdmin(Observacion $observacion): void
    {
        try {
            $excluidos = array_values(array_filter([
                $observacion->created_by ?? auth()->id(),
                $observacion->responsable_id,
            ]));

            $rolDelTipo = $observacion->origen === 'externa'
                ? TaxonomiaIncidencias::rolDeTipo($observacion->tipo)
                : null;

            $destinatarios = User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', User::ROL_SUPER_ADMIN))
                ->when($excluidos !== [], fn ($q) => $q->whereKeyNot($excluidos))
                ->when($rolDelTipo, fn ($q, $rol) => $q->whereDoesntHave('roles', fn ($r) => $r->where('name', $rol)))
                ->get();

            if ($destinatarios->isNotEmpty()) {
                Notification::send($destinatarios, new ObservacionCreadaNotification($observacion));
            }
        } catch (Throwable $e) {
            Log::error("No se pudo avisar a los super-admin del alta de {$observacion->numero}: {$e->getMessage()}");
        }
    }

    /**
     * Aviso al responsable cuando el caso entra en prioridad crítica.
     *
     * Tres condiciones que valen la pena tener presentes:
     *
     * - Solo al **entrar** en crítica (`wasChanged` + valor nuevo), no ante
     *   cualquier movimiento de prioridad.
     * - **No** si en el mismo guardado cambió el responsable: el aviso de
     *   asignación ya viaja con la prioridad nueva y sale en rojo, así que este
     *   sería el mismo hecho contado dos veces.
     * - **No** a quien hizo el cambio. Cuando Calidad clasifica un caso de otra
     *   persona el aviso sirve; cuando el propio responsable se lo marca
     *   crítico, avisarle de su propia acción es ruido.
     */
    private function avisarSiPasoACritica(Observacion $observacion): void
    {
        if (! $observacion->wasChanged('prioridad') || $observacion->prioridad !== 'critica') {
            return;
        }

        if ($observacion->wasChanged('responsable_id') || $observacion->responsable_id === null) {
            return;
        }

        if ($observacion->responsable_id === auth()->id()) {
            return;
        }

        User::find($observacion->responsable_id)?->notify(new ObservacionCriticaNotification($observacion));
    }

    /**
     * Una entrada de bitácora por cada campo relevante que cambió en este
     * update. Solo corre en `updated()` (no en `created()`): una observación
     * recién creada no tiene "cambios" que contar, la bitácora arranca en la
     * primera modificación real.
     *
     * Los valores se resuelven a texto legible **en este momento**, no se
     * guardan los IDs crudos: es un registro histórico, así que tiene que
     * seguir contando lo mismo aunque después renombren un sector o borren
     * un usuario. `getOriginal()` da el valor de antes de este save.
     */
    private function registrarCambios(Observacion $observacion): void
    {
        if ($observacion->wasChanged('estado')) {
            $this->registrar($observacion, ObservationHistory::ACCION_ESTADO, [
                'de' => Observacion::ESTADOS[$observacion->getOriginal('estado')] ?? $observacion->getOriginal('estado'),
                'a' => Observacion::ESTADOS[$observacion->estado] ?? $observacion->estado,
            ]);
        }

        if ($observacion->wasChanged('responsable_id')) {
            $this->registrar($observacion, ObservationHistory::ACCION_RESPONSABLE, [
                'de' => $this->nombreUsuario($observacion->getOriginal('responsable_id')),
                'a' => $this->nombreUsuario($observacion->responsable_id),
            ]);
        }

        if ($observacion->wasChanged('sector_id')) {
            $this->registrar($observacion, ObservationHistory::ACCION_SECTOR, [
                'de' => $this->nombreSector($observacion->getOriginal('sector_id')),
                'a' => $this->nombreSector($observacion->sector_id),
            ]);
        }

        // Prioridad y tipo de caso van juntos: así se cargan en el formulario de
        // clasificación, y separarlos en dos entradas no aporta nada al relato.
        if ($observacion->wasChanged('prioridad') || $observacion->wasChanged('tipo_caso')) {
            $prioridades = config('incidencias.prioridades');

            $this->registrar($observacion, ObservationHistory::ACCION_CLASIFICACION, [
                'prioridad' => [
                    'de' => $prioridades[$observacion->getOriginal('prioridad')] ?? $observacion->getOriginal('prioridad') ?? 'Sin clasificar',
                    'a' => $prioridades[$observacion->prioridad] ?? $observacion->prioridad ?? 'Sin clasificar',
                ],
                'tipo_caso' => [
                    'de' => $observacion->getOriginal('tipo_caso') ?? 'Sin clasificar',
                    'a' => $observacion->tipo_caso ?? 'Sin clasificar',
                ],
            ]);
        }
    }

    /** @param  array<string, mixed>  $cambios */
    private function registrar(Observacion $observacion, string $accion, array $cambios): void
    {
        $observacion->historial()->create([
            'user_id' => auth()->id(),
            'accion' => $accion,
            'cambios' => $cambios,
        ]);
    }

    private function nombreUsuario(?int $id): string
    {
        if ($id === null) {
            return 'Sin asignar';
        }

        return User::find($id)?->name ?? "Usuario #{$id}";
    }

    private function nombreSector(?int $id): string
    {
        if ($id === null) {
            return 'Sin sector';
        }

        return Sector::find($id)?->nombre ?? "Sector #{$id}";
    }
}
