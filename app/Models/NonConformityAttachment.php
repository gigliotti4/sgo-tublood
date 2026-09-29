<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evidencia o adjunto de una No Conformidad. Molde de `ObservationAttachment`. */
class NonConformityAttachment extends Model
{
    protected $fillable = [
        'non_conformity_id',
        'non_conformity_history_id',
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
