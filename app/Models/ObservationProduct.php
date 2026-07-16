<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationProduct extends Model
{
    protected $fillable = [
        'observation_id',
        'producto',
        'codigo',
        'cantidad_afectada',
        'lote',
        'fecha_vencimiento',
        'numero_remito',
        'tipo_comprobante',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
    ];

    public function observacion(): BelongsTo
    {
        return $this->belongsTo(Observacion::class, 'observation_id');
    }
}
