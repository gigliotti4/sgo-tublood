<?php

namespace App\Models;

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

    public const ACCION_ADJUNTO = 'adjunto';

    public const ACCION_SISTEMA = 'sistema';

    public const ACCIONES = [
        self::ACCION_COMENTARIO,
        self::ACCION_ESTADO,
        self::ACCION_RESPONSABLE,
        self::ACCION_SECTOR,
        self::ACCION_CLASIFICACION,
        self::ACCION_ADJUNTO,
        self::ACCION_SISTEMA,
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

    public function observacion(): BelongsTo
    {
        return $this->belongsTo(Observacion::class, 'observation_id');
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
