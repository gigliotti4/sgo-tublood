<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un producto afectado por una No Conformidad (sección 2 del Informe de Desvío).
 *
 * Mismo molde que `ObservationProduct`, sin remito ni comprobante: hasta el
 * 21/10/2026 los afectados eran un texto libre que no registraba los productos
 * como lo hace una Falla de producto. Los pedidos o procesos afectados, que no
 * son un producto, siguen en el texto `non_conformities.afectados`.
 */
class NonConformityProduct extends Model
{
    protected $fillable = [
        'non_conformity_id',
        'codigo',
        'producto',
        'cantidad_afectada',
        'tipo_presentacion',
        'lote',
        'fecha_vencimiento',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
    ];

    public function noConformidad(): BelongsTo
    {
        return $this->belongsTo(NoConformidad::class, 'non_conformity_id');
    }

    /** Sin FK, igual que en las observaciones: el código puede venir tipeado a mano. */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'codigo', 'codigo');
    }
}
