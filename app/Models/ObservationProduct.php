<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationProduct extends Model
{
    /** A qué corresponde la cantidad afectada. */
    public const PRESENTACIONES = [
        'presentacion_venta' => 'Presentación de venta',
        'unidades' => 'Unidades',
        'bultos' => 'Bultos',
    ];

    protected $fillable = [
        'observation_id',
        'producto',
        'codigo',
        'cantidad_afectada',
        'tipo_presentacion',
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

    /**
     * Solo de lectura, sin constraint en base: `codigo` es texto libre (puede
     * venir tipeado a mano desde el portal) y no siempre matchea un artículo
     * del catálogo sincronizado.
     */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'codigo', 'codigo');
    }
}
