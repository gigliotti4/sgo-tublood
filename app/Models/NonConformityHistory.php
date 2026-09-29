<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Entrada de la bitácora de una No Conformidad.
 *
 * Inmutable a propósito, y acá no es solo una buena práctica: §7 del
 * instructivo lo exige — "la información histórica no podrá eliminarse ni
 * reemplazarse". Se cierra por los dos lados: no hay ruta de edición ni de
 * borrado, y el modelo lo bloquea para que un `tinker` distraído no lo rompa en
 * silencio.
 *
 * Molde de `ObservationHistory`, con las acciones propias del flujo de NC.
 */
class NonConformityHistory extends Model
{
    public const ACCION_COMENTARIO = 'comentario';

    public const ACCION_ESTADO = 'estado';

    /** §4.3 — la NC queda formalmente abierta y recibe su número. */
    public const ACCION_APROBACION = 'aprobacion';

    /** §4.3 — vuelve a Borrador. El motivo es obligatorio y va en `nota`. */
    public const ACCION_DEVOLUCION = 'devolucion';

    /** §4.3 — no corresponde abrir la NC. Motivo obligatorio en `nota`. */
    public const ACCION_RECHAZO = 'rechazo';

    /** §5 — `cambios` = ['sumadas' => [...], 'sacadas' => [...]] con los números. */
    public const ACCION_OBSERVACIONES = 'observaciones';

    /** §4.4 — se guardó o completó la investigación y el análisis de causa. */
    public const ACCION_INVESTIGACION = 'investigacion';

    /** Sección 3 — `cambios` = ['agregada'|'quitada' => true] + la acción en `nota`. */
    public const ACCION_CONTENCION = 'contencion';

    /** Cambio de responsable del caso. `cambios` = ['de' => nombre, 'a' => nombre]. */
    public const ACCION_RESPONSABLE = 'responsable';

    /** §4.5 — se agregó, editó o quitó una acción del plan. */
    public const ACCION_PLAN = 'plan';

    /** §4.6 — avance de una acción. `cambios` = ['accion' => ..., 'estado' => ...]. */
    public const ACCION_AVANCE = 'avance';

    /** §4.7 — `cambios` = ['resultado' => 'Eficaz'|'Ineficaz'|...]. */
    public const ACCION_VERIFICACION = 'verificacion';

    /** §4.8 — cierre formal. El resultado final va en `nota`. */
    public const ACCION_CIERRE = 'cierre';

    public const ACCION_ADJUNTO = 'adjunto';

    /** §4.8 — solo super-admin. Motivo obligatorio en `nota`. */
    public const ACCION_REAPERTURA = 'reapertura';

    public const ACCION_CANCELACION = 'cancelacion';

    public const ACCION_SISTEMA = 'sistema';

    public const ACCIONES = [
        self::ACCION_COMENTARIO,
        self::ACCION_ESTADO,
        self::ACCION_APROBACION,
        self::ACCION_DEVOLUCION,
        self::ACCION_RECHAZO,
        self::ACCION_OBSERVACIONES,
        self::ACCION_INVESTIGACION,
        self::ACCION_CONTENCION,
        self::ACCION_RESPONSABLE,
        self::ACCION_PLAN,
        self::ACCION_AVANCE,
        self::ACCION_VERIFICACION,
        self::ACCION_CIERRE,
        self::ACCION_ADJUNTO,
        self::ACCION_REAPERTURA,
        self::ACCION_CANCELACION,
        self::ACCION_SISTEMA,
    ];

    /**
     * Etiquetas en español de cada acción. La pantalla usa `accionLabels` de
     * `resources/js/lib/nc.ts` — mismo contenido, mantenerlos sincronizados si
     * se agrega una acción.
     */
    public const ACCION_LABELS = [
        self::ACCION_COMENTARIO => 'Comentario',
        self::ACCION_ESTADO => 'Cambio de estado',
        self::ACCION_APROBACION => 'Aprobación',
        self::ACCION_DEVOLUCION => 'Devolución',
        self::ACCION_RECHAZO => 'Rechazo',
        self::ACCION_OBSERVACIONES => 'Observaciones vinculadas',
        self::ACCION_INVESTIGACION => 'Investigación',
        self::ACCION_CONTENCION => 'Acción de contención',
        self::ACCION_RESPONSABLE => 'Responsable del caso',
        self::ACCION_PLAN => 'Plan de acción',
        self::ACCION_AVANCE => 'Avance de acción',
        self::ACCION_VERIFICACION => 'Verificación de eficacia',
        self::ACCION_CIERRE => 'Cierre',
        self::ACCION_ADJUNTO => 'Archivo adjunto',
        self::ACCION_REAPERTURA => 'Reapertura',
        self::ACCION_CANCELACION => 'Cancelación',
        self::ACCION_SISTEMA => 'Sistema',
    ];

    /** Sin `updated_at`: una entrada no se modifica. */
    const UPDATED_AT = null;

    protected $table = 'non_conformity_history';

    protected $fillable = [
        'non_conformity_id',
        'user_id',
        'accion',
        'nota',
        'cambios',
    ];

    protected $casts = [
        'cambios' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('El historial de una No Conformidad es inmutable: no se puede editar una entrada.');
        });

        static::deleting(function () {
            throw new RuntimeException('El historial de una No Conformidad es inmutable: no se puede borrar una entrada.');
        });
    }

    /**
     * `withTrashed()` es obligatorio: sin él, cada entrada de una NC borrada
     * resuelve a `noConformidad === null` — justo el caso donde el registro
     * inmutable más importa.
     */
    public function noConformidad(): BelongsTo
    {
        return $this->belongsTo(NoConformidad::class, 'non_conformity_id')->withTrashed();
    }

    /** Null en las entradas automáticas (observer, comandos). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adjuntos(): HasMany
    {
        return $this->hasMany(NonConformityAttachment::class, 'non_conformity_history_id');
    }
}
