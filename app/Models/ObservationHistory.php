<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Entrada de la bitácora del caso: un cambio (estado, responsable, sector,
 * clasificación), un comentario manual, o una acción del sistema.
 *
 * Inmutable a propósito — es el historial que exige un sistema de calidad, y
 * nadie (ni el admin) puede editarlo ni borrarlo. Se cierra por los dos lados:
 * no hay ruta de edición/borrado, y el modelo lo bloquea acá para que un
 * `tinker` distraído no lo rompa en silencio.
 */
class ObservationHistory extends Model
{
    public const ACCION_COMENTARIO = 'comentario';

    public const ACCION_ESTADO = 'estado';

    public const ACCION_RESPONSABLE = 'responsable';

    public const ACCION_SECTOR = 'sector';

    public const ACCION_CLASIFICACION = 'clasificacion';

    /**
     * Corrección del texto del reclamo (título y/o descripción).
     *
     * ⚠️ `cambios` guarda el valor **viejo y el nuevo completos**, solo de los
     * campos que cambiaron: `['titulo' => ['de' => ..., 'a' => ...], ...]`. No
     * es verborragia — la descripción suele ser lo que escribió el cliente, y
     * si se pudiera reescribir sin dejar el original, el reclamo tal como entró
     * dejaría de existir. Por eso esta acción es la única que duplica texto
     * largo en la bitácora.
     */
    public const ACCION_CONTENIDO = 'contenido';

    /**
     * El super-admin le cambió el tipo de incidencia (desde el 21/10/2026).
     * `cambios` = `['de' => etiqueta, 'a' => etiqueta, 'datos_anteriores' => [...]]`:
     * los "Datos específicos" del tipo viejo dejan de corresponder y se
     * reemplazan por los del nuevo, así que acá queda lo que se había cargado.
     */
    public const ACCION_TIPO = 'tipo';

    public const ACCION_ADJUNTO = 'adjunto';

    public const ACCION_NOTIFICADOS = 'notificados';

    public const ACCION_SISTEMA = 'sistema';

    /** `cambios` = ['tipo' => 'cancelacion'|'borrado']; el motivo va en `nota`. */
    public const ACCION_BAJA = 'baja';

    public const ACCION_RESTAURACION = 'restauracion';

    /**
     * Prórroga de plazo (desde el 6/11/2026). Las escribe el controller y no el
     * observer: la prórroga vive en su propia tabla y lo que hay que dejar
     * dicho es el porqué (motivo, justificación, comentario de quien decide).
     * `cambios` = `['fecha' => 'dd/mm/aaaa', 'motivo' => etiqueta, ...]`.
     */
    public const ACCION_PRORROGA_PEDIDA = 'prorroga_pedida';

    /** `cambios` = `['decision' => 'aprobada'|'rechazada', 'fecha' => ...]`; el comentario va en `nota`. */
    public const ACCION_PRORROGA_RESUELTA = 'prorroga_resuelta';

    /**
     * Cambió el "responsable del fallo". `cambios` = `['de' => nombre, 'a' => nombre]`.
     *
     * ⚠️ **Es información restringida** (solo jefes y Calidad): estas entradas
     * se esconden de quien no puede ver el campo, en la bitácora del caso, en el
     * PDF y en la sección Bitácora. Ver `scopeVisiblesPara()`.
     */
    public const ACCION_RESPONSABLE_FALLO = 'responsable_fallo';

    public const ACCIONES = [
        self::ACCION_COMENTARIO,
        self::ACCION_ESTADO,
        self::ACCION_RESPONSABLE,
        self::ACCION_SECTOR,
        self::ACCION_CLASIFICACION,
        self::ACCION_CONTENIDO,
        self::ACCION_TIPO,
        self::ACCION_ADJUNTO,
        self::ACCION_NOTIFICADOS,
        self::ACCION_SISTEMA,
        self::ACCION_BAJA,
        self::ACCION_RESTAURACION,
        self::ACCION_PRORROGA_PEDIDA,
        self::ACCION_PRORROGA_RESUELTA,
        self::ACCION_RESPONSABLE_FALLO,
    ];

    /**
     * Etiquetas en español de cada acción, para el PDF del detalle (la
     * pantalla usa `accionLabels` en resources/js/lib/bitacora.ts — mismo
     * contenido, mantenerlos sincronizados si se agrega una acción).
     */
    public const ACCION_LABELS = [
        self::ACCION_COMENTARIO => 'Comentario',
        self::ACCION_ESTADO => 'Cambio de estado',
        self::ACCION_RESPONSABLE => 'Cambio de responsable',
        self::ACCION_SECTOR => 'Derivación de sector',
        self::ACCION_CLASIFICACION => 'Clasificación',
        self::ACCION_CONTENIDO => 'Corrección del texto',
        self::ACCION_TIPO => 'Cambio de tipo',
        self::ACCION_ADJUNTO => 'Archivo adjunto',
        self::ACCION_NOTIFICADOS => 'Usuarios a notificar',
        self::ACCION_SISTEMA => 'Sistema',
        self::ACCION_BAJA => 'Baja',
        self::ACCION_RESTAURACION => 'Restauración',
        self::ACCION_PRORROGA_PEDIDA => 'Prórroga pedida',
        self::ACCION_PRORROGA_RESUELTA => 'Prórroga resuelta',
        self::ACCION_RESPONSABLE_FALLO => 'Responsable del fallo',
    ];

    /** Sin `updated_at`: una entrada no se modifica. Eloquent sigue completando `created_at` solo. */
    const UPDATED_AT = null;

    protected $table = 'observation_history';

    protected $fillable = [
        'observation_id',
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
            throw new RuntimeException('El historial de una observación es inmutable: no se puede editar una entrada.');
        });

        static::deleting(function () {
            throw new RuntimeException('El historial de una observación es inmutable: no se puede borrar una entrada.');
        });
    }

    /**
     * Las entradas que `$user` puede leer. A quien no ve el "responsable del
     * fallo" se le sacan las que lo nombran: la bitácora es otra puerta de
     * entrada al mismo dato, y esconder el campo en la ficha pero dejarlo en el
     * historial no esconde nada.
     *
     * `$user` es opcional por el PDF y los contextos sin sesión: sin usuario se
     * asume el caso restringido.
     */
    public function scopeVisiblesPara(Builder $query, ?User $user): Builder
    {
        if ($user?->veResponsableDelFallo()) {
            return $query;
        }

        return $query->where('accion', '!=', self::ACCION_RESPONSABLE_FALLO);
    }

    /**
     * `withTrashed()` es obligatorio: sin él, cada entrada de bitácora de una
     * observación borrada resuelve a `observacion === null` — justo el caso
     * donde el registro inmutable más importa.
     */
    public function observacion(): BelongsTo
    {
        return $this->belongsTo(Observacion::class, 'observation_id')->withTrashed();
    }

    /** Null en las entradas automáticas (observer, comandos): no tienen usuario detrás. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adjuntos(): HasMany
    {
        return $this->hasMany(ObservationAttachment::class, 'observation_history_id');
    }
}
