<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationAttachment extends Model
{
    protected $fillable = [
        'observation_id',
        'observation_history_id',
        'user_id',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    public function observacion(): BelongsTo
    {
        return $this->belongsTo(Observacion::class, 'observation_id');
    }

    /** Null en los adjuntos cargados antes de la bitácora (portal, alta). */
    public function entradaHistorial(): BelongsTo
    {
        return $this->belongsTo(ObservationHistory::class, 'observation_history_id');
    }

    /** Quién lo subió. Null en los adjuntos cargados antes de la bitácora. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
