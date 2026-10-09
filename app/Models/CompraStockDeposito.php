<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stock de un artículo en un depósito.
 *
 * Espejo de solo lectura de `powerbi_stock_vista`: refresh completo en cada
 * sincronización. Es lo que permite elegir en el tablero qué depósitos cuentan.
 */
class CompraStockDeposito extends Model
{
    protected $table = 'compras_stock_depositos';

    protected $guarded = [];

    protected $casts = [
        'cant_stock' => 'decimal:4',
        'synced_at' => 'datetime',
    ];
}
