<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evidencia o adjunto de una No Conformidad. Molde de `ObservationAttachment`. */
class NonConformityAttachment extends Model
{
    /**
     * La ilustración de "Riesgo involucrado" (sección 2, desde el 21/10/2026).
     * Se muestra en su sección y no entre las evidencias sueltas.
     */
    public const SECCION_RIESGO = 'riesgo';

    protected $fillable = [
        'non_conformity_id',
        'non_conformity_history_id',
        // Null = evidencia suelta. Ver `SECCION_RIESGO`.
        'seccion',
        'user_id',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    public function noConformidad(): BelongsTo
    {
        return $this->belongsTo(NoConformidad::class, 'non_conformity_id');
    }

    /** Null cuando el archivo es una evidencia suelta de la NC, no de un comentario. */
    public function entradaHistorial(): BelongsTo
    {
        return $this->belongsTo(NonConformityHistory::class, 'non_conformity_history_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
