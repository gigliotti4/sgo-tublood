<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una factura proforma del ERP (`DDD`), a nivel cabecera.
 *
 * Espejo de solo lectura de `powerbi_pedidos_vista`: refresh completo en cada
 * `proformas:sync`. Si es urgente o no **no** se guarda: lo decide
 * `App\Support\Urgencia` al armar el tablero, para que corregir la regla no
 * obligue a resincronizar.
 */
class Proforma extends Model
{
    protected $guarded = [];

    protected $casts = [
        'fecha' => 'date',
        'synced_at' => 'datetime',
    ];
}
