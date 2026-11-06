<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un remito de venta del ERP (series `VentaPartida::SERIES_DE_REMITO`), a nivel cabecera.
 *
 * Espejo de solo lectura de `powerbi_mov_stock_vista`: refresh completo en cada
 * `remitos:sync`. Es el denominador de la tasa de fallos y la medida de "pedidos"
 * con la que escala el objetivo de armado.
 */
class Remito extends Model
{
    protected $guarded = [];

    protected $casts = [
        'fecha' => 'date',
        'synced_at' => 'datetime',
    ];
}
