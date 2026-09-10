<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Renglón de orden de compra pendiente de entrega.
 *
 * Espejo de solo lectura de `powerbi_ordenescompra_pend_vista`: refresh
 * completo en cada sincronización.
 */
class CompraOrdenPendiente extends Model
{
    protected $table = 'compras_ordenes_pendientes';

    protected $guarded = [];

    protected $casts = [
        'numero' => 'integer',
        'item' => 'integer',
        'proveedor_numero' => 'integer',
        'fecha' => 'date',
        'fecha_entrega' => 'date',
        'cant_pend' => 'decimal:4',
        'synced_at' => 'datetime',
    ];
}
