<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pedido de prórroga del plazo de una observación, con su resolución.
 *
 * Mientras hay una aprobada y vigente, `observations.prorroga_hasta` lo
 * refleja y la observación figura "En prórroga" (no es un estado: es derivado,
 * ver `Observacion::enProrroga()`).
 */
class ObservationExtension extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_APROBADA = 'aprobada';

    public const ESTADO_RECHAZADA = 'rechazada';

    public const ESTADOS = [
        self::ESTADO_PENDIENTE => 'Pendiente',
        self::ESTADO_APROBADA => 'Aprobada',
        self::ESTADO_RECHAZADA => 'Rechazada',
    ];

    /** Por qué se pide más plazo. El tablero los cuenta, así que son una lista cerrada. */
    public const MOTIVOS = [
        'laboratorio' => 'Ensayos de laboratorio (Calidad)',
        'proveedor' => 'Respuesta del proveedor',
        'cliente' => 'Respuesta o muestras del cliente',
        'otro' => 'Otro',
    ];

    protected $fillable = [
        'observation_id',
        'solicitada_por',
        'fecha_pedida',
        'motivo',
        'que_se_hace',
        'justificacion',
        'vencimiento_previo',
        'estado',
        'decidida_por',
        'decidida_at',
        'fecha_autorizada',
        'comentario',
    ];

    protected $casts = [
        'fecha_pedida' => 'date',
        'fecha_autorizada' => 'date',
        'vencimiento_previo' => 'datetime',
        'decidida_at' => 'datetime',
    ];

    public function observacion(): BelongsTo
    {
        return $this->belongsTo(Observacion::class, 'observation_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitada_por');
    }

    public function decisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidida_por');
    }

    public function estaPendiente(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }
}
